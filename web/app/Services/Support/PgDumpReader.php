<?php

namespace App\Services\Support;

use RuntimeException;

/**
 * Reads the rows of selected tables from a gzip-compressed plain-SQL `pg_dump` (COPY ... FROM stdin
 * blocks in text format), e.g. benchmark/results/benchmark-v1.0/<slug>/db.sql.gz.
 *
 * Read-only and without a database: the demo uses it to replay recorded LLM jobs of the official
 * experiment (S13-03). Values are returned as PostgreSQL text (null for \N); callers decode jsonb.
 */
final class PgDumpReader
{
    private const ESCAPES = [
        'b' => "\x08",
        'f' => "\x0C",
        'n' => "\n",
        'r' => "\r",
        't' => "\t",
        'v' => "\x0B",
    ];

    /**
     * @param  list<string>  $tables  Table names in schema `public`.
     * @return array<string, list<array<string, string|null>>> Rows per requested table (missing tables: []).
     *
     * @throws RuntimeException When the file cannot be read.
     */
    public function tables(string $gzPath, array $tables): array
    {
        $result = array_fill_keys($tables, []);
        if (! is_file($gzPath) || ! function_exists('gzopen')) {
            throw new RuntimeException("Không đọc được dump {$gzPath}.");
        }

        $handle = @gzopen($gzPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Không đọc được dump {$gzPath}.");
        }

        try {
            $current = null;
            $columns = [];
            while (($line = gzgets($handle)) !== false) {
                $line = rtrim($line, "\n");
                if ($current === null) {
                    if (preg_match('/^COPY public\.(\w+) \((.*)\) FROM stdin;$/', $line, $match) === 1
                        && array_key_exists($match[1], $result)) {
                        $current = $match[1];
                        $columns = array_map('trim', explode(',', $match[2]));
                    }

                    continue;
                }
                if ($line === '\\.') {
                    $current = null;

                    continue;
                }
                $values = array_map(fn (string $field): ?string => $this->unescape($field), explode("\t", $line));
                if (count($values) !== count($columns)) {
                    throw new RuntimeException("Dòng COPY của bảng {$current} không khớp số cột.");
                }
                $result[$current][] = array_combine($columns, $values);
            }
        } finally {
            gzclose($handle);
        }

        return $result;
    }

    /**
     * COPY text format: `\N` is NULL; a backslash escapes the next character (\b \f \n \r \t \v, or
     * the character itself, e.g. `\\`).
     */
    private function unescape(string $field): ?string
    {
        if ($field === '\\N') {
            return null;
        }
        if (! str_contains($field, '\\')) {
            return $field;
        }

        return (string) preg_replace_callback(
            '/\\\\(.)/s',
            static fn (array $m): string => self::ESCAPES[$m[1]] ?? $m[1],
            $field,
        );
    }
}
