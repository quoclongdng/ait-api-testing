<?php

namespace App\Services\Generation;

use App\Enums\TestCaseStatus;
use App\Services\TestEngine\TestFingerprintService;

/**
 * Applies max_cases_per_endpoint to the RULE output of one endpoint after validation and dedup.
 *
 * RuleGenerator returns cases grouped by technique (HAPPY_PATH, MISSING_REQUIRED, ...), and inside a
 * technique grouped by field. Cutting that list in order dropped whole techniques (e.g. every
 * BOUNDARY case of POST /products), so the RULE strategy could not detect some seeded bugs by
 * construction. Selection is instead:
 *   1. VALID cases whose fingerprint is new (not in the suite, not earlier in the batch) first;
 *   2. round-robin across techniques, and inside a technique round-robin across target fields
 *      (negative cases, i.e. expected 4xx, before positive ones for the same field);
 *   3. remaining budget filled with duplicates, then INVALID cases, so stats stay truthful.
 * Selected cases keep their generation order.
 */
final class RuleCaseSelector
{
    public function __construct(private readonly TestFingerprintService $fingerprints) {}

    /**
     * @param  list<array<string, mixed>>  $records  rows with at least `dsl` (array) and `status` (TestCaseStatus)
     * @param  array<string, true>  $knownFingerprints  fingerprints already stored in the suite
     * @return list<array<string, mixed>>
     */
    public function select(array $records, int $cap, array $knownFingerprints = []): array
    {
        $cap = max(1, $cap);
        if (count($records) <= $cap) {
            return $records;
        }

        $seen = $knownFingerprints;
        $preferred = [];
        $duplicates = [];
        $invalid = [];
        foreach ($records as $index => $record) {
            $dsl = $this->dsl($record);
            if (($record['status'] ?? null) !== TestCaseStatus::Valid || $dsl === null) {
                $invalid[] = $index;

                continue;
            }
            $fingerprint = $this->fingerprints->calculate($dsl);
            if (isset($seen[$fingerprint])) {
                $duplicates[] = $index;

                continue;
            }
            $seen[$fingerprint] = true;
            $preferred[] = $index;
        }

        $ordered = [...$this->interleave($records, $preferred), ...$duplicates, ...$invalid];
        $picked = array_slice($ordered, 0, $cap);
        sort($picked);

        return array_map(fn (int $index): array => $records[$index], $picked);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<int>  $indexes
     * @return list<int>
     */
    private function interleave(array $records, array $indexes): array
    {
        /** @var array<string, array<string, list<int>>> $byTechnique technique => subject => indexes */
        $byTechnique = [];
        foreach ($indexes as $index) {
            $dsl = $this->dsl($records[$index]) ?? [];
            $technique = is_string($dsl['technique'] ?? null) ? $dsl['technique'] : '';
            $byTechnique[$technique][$this->subject($dsl)][] = $index;
        }

        $perTechnique = [];
        foreach ($byTechnique as $subjects) {
            $buckets = [];
            foreach ($subjects as $bucket) {
                // Negative cases first: they are the ones that expose missing validation.
                usort($bucket, function (int $a, int $b) use ($records): int {
                    $byPolarity = (int) ! $this->isNegative($this->dsl($records[$a]) ?? []) <=> (int) ! $this->isNegative($this->dsl($records[$b]) ?? []);

                    return $byPolarity !== 0 ? $byPolarity : $a <=> $b;
                });
                $buckets[] = $bucket;
            }
            $perTechnique[] = $this->roundRobin($buckets);
        }

        return $this->roundRobin($perTechnique);
    }

    /**
     * @param  list<list<int>>  $lists
     * @return list<int>
     */
    private function roundRobin(array $lists): array
    {
        $result = [];
        for ($i = 0, $max = $lists === [] ? 0 : max(array_map('count', $lists)); $i < $max; $i++) {
            foreach ($lists as $list) {
                if (isset($list[$i])) {
                    $result[] = $list[$i];
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function dsl(array $record): ?array
    {
        $dsl = $record['dsl'] ?? null;

        return is_array($dsl) ? $dsl : null;
    }

    /**
     * Target of a RULE case: the first quoted name in its title ("... field 'price' ...").
     *
     * @param  array<string, mixed>  $dsl
     */
    private function subject(array $dsl): string
    {
        $title = is_string($dsl['title'] ?? null) ? $dsl['title'] : '';

        return preg_match("/'([^']*)'/", $title, $m) === 1 ? $m[1] : '';
    }

    /**
     * @param  array<string, mixed>  $dsl
     */
    private function isNegative(array $dsl): bool
    {
        $steps = is_array($dsl['steps'] ?? null) ? $dsl['steps'] : [];
        $first = is_array($steps[0] ?? null) ? $steps[0] : [];
        foreach (is_array($first['assertions'] ?? null) ? $first['assertions'] : [] as $assertion) {
            if (is_array($assertion) && ($assertion['type'] ?? null) === 'status' && is_int($assertion['equals'] ?? null)) {
                return $assertion['equals'] >= 400;
            }
        }

        return false;
    }
}
