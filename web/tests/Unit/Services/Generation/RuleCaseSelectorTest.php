<?php

namespace Tests\Unit\Services\Generation;

use App\Enums\TestCaseStatus;
use App\Services\Generation\RuleCaseSelector;
use App\Services\TestEngine\TestFingerprintService;
use PHPUnit\Framework\TestCase;

class RuleCaseSelectorTest extends TestCase
{
    /**
     * @return array{dsl: array<string, mixed>, status: TestCaseStatus}
     */
    private function record(string $technique, string $field, int $status, mixed $value, TestCaseStatus $caseStatus = TestCaseStatus::Valid): array
    {
        return [
            'status' => $caseStatus,
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => "{$technique} field '{$field}' {$status}",
                'endpoint' => ['method' => 'POST', 'path' => '/items'],
                'technique' => $technique,
                'source' => 'RULE',
                'steps' => [[
                    'name' => 'Call',
                    'request' => ['method' => 'POST', 'path' => '/items', 'body' => [$field => $value]],
                    'assertions' => [['type' => 'status', 'equals' => $status]],
                ]],
            ],
        ];
    }

    private function selector(): RuleCaseSelector
    {
        return new RuleCaseSelector(new TestFingerprintService);
    }

    public function test_it_keeps_every_technique_and_field_within_the_cap(): void
    {
        $records = [
            $this->record('HAPPY_PATH', '', 201, 1),
            $this->record('BOUNDARY', 'sku', 201, 'a'),
            $this->record('BOUNDARY', 'sku', 422, ''),
            $this->record('BOUNDARY', 'name', 201, 'b'),
            $this->record('BOUNDARY', 'name', 422, ''),
            $this->record('BOUNDARY', 'price', 201, 0),
            $this->record('BOUNDARY', 'price', 422, -1),
            $this->record('EQUIVALENCE', 'price', 422, -5),
        ];

        $picked = array_map(fn (array $r): string => $r['dsl']['title'], $this->selector()->select($records, 5));

        $this->assertSame([
            "HAPPY_PATH field '' 201",
            "BOUNDARY field 'sku' 422",
            "BOUNDARY field 'name' 422",
            "BOUNDARY field 'price' 422",
            "EQUIVALENCE field 'price' 422",
        ], $picked);
    }

    public function test_duplicates_and_invalid_cases_only_fill_the_remaining_budget(): void
    {
        $duplicate = $this->record('BOUNDARY', 'sku', 422, '');
        $records = [
            $this->record('BOUNDARY', 'sku', 422, ''),
            $duplicate,
            $this->record('TYPE_MISMATCH', 'sku', 422, 1, TestCaseStatus::Invalid),
            $this->record('BOUNDARY', 'name', 422, ''),
        ];

        $picked = $this->selector()->select($records, 3);
        $this->assertSame([$records[0], $records[1], $records[3]], $picked);

        $fingerprint = (new TestFingerprintService)->calculate($records[3]['dsl']);
        $picked = $this->selector()->select($records, 2, [$fingerprint => true]);
        $this->assertSame([$records[0], $records[1]], $picked);
    }

    public function test_it_returns_everything_under_the_cap(): void
    {
        $records = [$this->record('HAPPY_PATH', '', 201, 1)];
        $this->assertSame($records, $this->selector()->select($records, 10));
    }
}
