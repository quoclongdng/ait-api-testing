<?php

namespace Tests\Unit\Services\Experiments;

use App\Services\Experiments\SpecCoverageAnalyzer;
use PHPUnit\Framework\TestCase;

class SpecCoverageAnalyzerTest extends TestCase
{
    /**
     * @return list<array{method: string, path: string, responses: array<array-key, mixed>}>
     */
    private function endpoints(): array
    {
        return [
            ['method' => 'GET', 'path' => '/products', 'responses' => ['200' => [], '422' => []]],
            ['method' => 'POST', 'path' => '/products', 'responses' => ['201' => [], '422' => [], 'default' => []]],
            ['method' => 'GET', 'path' => '/products/{id}', 'responses' => ['200' => [], '404' => []]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $assertions
     * @return array<string, mixed>
     */
    private function dsl(string $method, string $path, array $assertions): array
    {
        return [
            'endpoint' => ['method' => $method, 'path' => $path],
            'steps' => [
                ['name' => 's', 'request' => ['method' => $method, 'path' => $path], 'assertions' => $assertions],
            ],
        ];
    }

    public function test_counts_covered_endpoints_and_asserted_status_pairs(): void
    {
        $result = (new SpecCoverageAnalyzer)->analyze($this->endpoints(), [
            $this->dsl('POST', '/products', [['type' => 'status', 'equals' => 201]]),
            $this->dsl('POST', '/products', [['type' => 'status_in', 'values' => [422, 400]]]),
            $this->dsl('get', '/products/{id}', [['type' => 'status', 'equals' => 404]]),
            // The same pair asserted twice is counted once.
            $this->dsl('GET', '/products/{id}', [['type' => 'status', 'equals' => 404], ['type' => 'json_path', 'path' => '$.id', 'op' => 'exists']]),
        ]);

        $this->assertSame([
            'total_endpoints' => 3,
            'covered_endpoints' => 2,
            'total_status_pairs' => 6,
            'asserted_status_pairs' => 3,
        ], $result);
    }

    public function test_cases_for_unknown_endpoints_are_ignored(): void
    {
        $result = (new SpecCoverageAnalyzer)->analyze($this->endpoints(), [
            $this->dsl('DELETE', '/unknown', [['type' => 'status', 'equals' => 204]]),
            ['steps' => 'broken'],
        ]);

        $this->assertSame(0, $result['covered_endpoints']);
        $this->assertSame(0, $result['asserted_status_pairs']);
    }

    public function test_empty_spec_yields_zero_denominators(): void
    {
        $result = (new SpecCoverageAnalyzer)->analyze([], []);

        $this->assertSame(['total_endpoints' => 0, 'covered_endpoints' => 0, 'total_status_pairs' => 0, 'asserted_status_pairs' => 0], $result);
    }
}
