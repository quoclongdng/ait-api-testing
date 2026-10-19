<?php

namespace App\Services\Reports;

use App\Data\Reports\EndpointBreakdown;
use App\Data\Reports\ExportedFile;
use App\Data\Reports\RunReport;
use App\Data\Reports\RunReportSummary;
use App\Enums\ReportFormat;
use App\Http\Resources\TestResultResource;
use App\Http\Resources\TestRunResource;
use App\Models\TestResult;
use RuntimeException;

/**
 * Serialises a RunReport to JSON / CSV / HTML (M13, S8-01).
 *
 * Target responses and LLM-generated titles are untrusted (docs/DESIGN.md §9):
 * - HTML is a self-contained Blade view that only uses escaped output `{{ }}` and no scripts.
 * - CSV cells starting with a formula trigger are prefixed with a quote (CSV injection).
 */
class RunReportExporter
{
    /** UTF-8 byte order mark so Excel opens Vietnamese text correctly. */
    public const UTF8_BOM = "\xEF\xBB\xBF";

    /** Leading characters that make spreadsheet software evaluate a cell as a formula. */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    /** Whitespace some spreadsheet importers trim before deciding a cell is a formula. */
    private const LEADING_WHITESPACE = " \t\r\n\0\x0B";

    public function export(RunReport $report, ReportFormat $format): ExportedFile
    {
        $content = match ($format) {
            ReportFormat::Json => $this->toJson($report),
            ReportFormat::Csv => $this->toCsv($report),
            ReportFormat::Html => $this->toHtml($report),
        };

        return new ExportedFile(
            content: $content,
            contentType: $format->contentType(),
            filename: "test-run-{$report->run->id}-report.{$format->value}",
        );
    }

    /**
     * Neutralises spreadsheet formulas (OWASP CSV injection).
     */
    public static function sanitizeCsvCell(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $trimmed = ltrim($value, self::LEADING_WHITESPACE);
        if (in_array($value[0], self::FORMULA_TRIGGERS, true)
            || ($trimmed !== '' && in_array($trimmed[0], self::FORMULA_TRIGGERS, true))) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Matches docs/contracts/web-api.openapi.yaml#/components/schemas/TestRunReport.
     */
    private function toJson(RunReport $report): string
    {
        $payload = [
            'run' => (new TestRunResource($report->run))->resolve(),
            'summary' => $report->summary->toArray() + [
                'endpoints' => array_map(fn (EndpointBreakdown $e): array => $e->toArray(), $report->endpoints),
            ],
            'results' => $report->results
                ->map(fn (TestResult $r): array => (new TestResultResource($r))->resolve())
                ->values()
                ->all(),
        ];

        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    private function toCsv(RunReport $report): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Không thể tạo bộ đệm CSV.');
        }

        $this->writeCsvRow($handle, [
            'ID kết quả', 'ID test case', 'Tiêu đề', 'Phương thức', 'Endpoint', 'Kết quả',
            'Thời gian (ms)', 'Mã trạng thái', 'URL request', 'Assertion thất bại', 'Thông báo lỗi',
        ]);

        if ($report->summary->totalsOnly) {
            // No per-case row exists: one row carries the note and the stored totals, so the file is
            // not mistaken for an empty run (QA S14-06).
            $summary = $report->summary;
            $this->writeCsvRow($handle, [
                '', '', RunReportSummary::TOTALS_ONLY_NOTE, '', '',
                "Tổng {$summary->total}: PASS {$summary->pass}, FAIL {$summary->fail}, ERROR {$summary->error}, SKIPPED {$summary->skipped}",
                '', '', '', '', '',
            ]);
        }

        foreach ($report->results as $result) {
            $failed = [];
            foreach ($result->assertion_results as $assertion) {
                if (! is_array($assertion) || ($assertion['passed'] ?? false) === true) {
                    continue;
                }
                $line = RunReportBuilder::stringify($assertion['type'] ?? 'unknown')
                    .': kỳ vọng '.RunReportBuilder::stringify($assertion['expected'] ?? null)
                    .', thực tế '.RunReportBuilder::stringify($assertion['actual'] ?? null);
                if (isset($assertion['message'])) {
                    $line .= ' ('.RunReportBuilder::stringify($assertion['message']).')';
                }
                $failed[] = $line;
            }

            $status = RunReportBuilder::responseStatus($result);
            $url = $result->request_log['url'] ?? null;

            $this->writeCsvRow($handle, [
                (string) $result->id,
                (string) $result->test_case_id,
                $result->testCase->title,
                $result->testCase->endpoint->method->value,
                $result->testCase->endpoint->path,
                $result->verdict->value,
                (string) $result->duration_ms,
                $status === null ? '' : (string) $status,
                is_string($url) ? $url : '',
                implode(' | ', $failed),
                $result->error_message ?? '',
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return self::UTF8_BOM.$csv;
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $cells
     */
    private function writeCsvRow($handle, array $cells): void
    {
        $safe = array_map(
            fn (string $cell): string => self::sanitizeCsvCell(mb_scrub($cell, 'UTF-8')),
            $cells,
        );

        fputcsv($handle, $safe, ',', '"', '', "\r\n");
    }

    private function toHtml(RunReport $report): string
    {
        return view('reports.run-export', [
            'report' => $report,
            'generatedAt' => now(),
        ])->render();
    }
}
