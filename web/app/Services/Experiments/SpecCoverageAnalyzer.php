<?php

namespace App\Services\Experiments;

/**
 * Endpoint Coverage and Status Code Coverage inputs (docs/DESIGN.md §11).
 *
 * - An endpoint is covered when at least one VALID case targets it (`dsl.endpoint`).
 * - A pair (endpoint, status) is asserted when a step whose request targets that endpoint
 *   carries a `status` (equals) or `status_in` assertion containing the status. Only pairs
 *   declared in the spec (explicit 3-digit response codes) are counted.
 */
final class SpecCoverageAnalyzer
{
    /**
     * @param  list<array{method: string, path: string, responses: array<array-key, mixed>}>  $specEndpoints
     * @param  list<array<string, mixed>>  $validDsls
     * @return array{total_endpoints: int, covered_endpoints: int, total_status_pairs: int, asserted_status_pairs: int}
     */
    public function analyze(array $specEndpoints, array $validDsls): array
    {
        /** @var array<string, true> $endpointKeys */
        $endpointKeys = [];
        /** @var array<string, true> $specPairs */
        $specPairs = [];

        foreach ($specEndpoints as $endpoint) {
            $key = $this->key($endpoint['method'], $endpoint['path']);
            $endpointKeys[$key] = true;
            foreach (array_keys($endpoint['responses']) as $status) {
                $status = (string) $status;
                if (preg_match('/^[1-5]\d\d$/', $status) === 1) {
                    $specPairs[$key.'#'.$status] = true;
                }
            }
        }

        /** @var array<string, true> $covered */
        $covered = [];
        /** @var array<string, true> $asserted */
        $asserted = [];

        foreach ($validDsls as $dsl) {
            $endpoint = $dsl['endpoint'] ?? null;
            if (is_array($endpoint) && is_string($endpoint['method'] ?? null) && is_string($endpoint['path'] ?? null)) {
                $key = $this->key($endpoint['method'], $endpoint['path']);
                if (isset($endpointKeys[$key])) {
                    $covered[$key] = true;
                }
            }

            $steps = is_array($dsl['steps'] ?? null) ? $dsl['steps'] : [];
            foreach ($steps as $step) {
                if (! is_array($step) || ! is_array($step['request'] ?? null)) {
                    continue;
                }
                $request = $step['request'];
                if (! is_string($request['method'] ?? null) || ! is_string($request['path'] ?? null)) {
                    continue;
                }
                $stepKey = $this->key($request['method'], $request['path']);
                foreach ($this->assertedStatuses($step) as $status) {
                    $pair = $stepKey.'#'.$status;
                    if (isset($specPairs[$pair])) {
                        $asserted[$pair] = true;
                    }
                }
            }
        }

        return [
            'total_endpoints' => count($endpointKeys),
            'covered_endpoints' => count($covered),
            'total_status_pairs' => count($specPairs),
            'asserted_status_pairs' => count($asserted),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $step
     * @return list<int>
     */
    private function assertedStatuses(array $step): array
    {
        $assertions = is_array($step['assertions'] ?? null) ? $step['assertions'] : [];
        $statuses = [];
        foreach ($assertions as $assertion) {
            if (! is_array($assertion)) {
                continue;
            }
            $type = $assertion['type'] ?? null;
            if ($type === 'status' && is_int($assertion['equals'] ?? null)) {
                $statuses[] = $assertion['equals'];
            } elseif ($type === 'status_in' && is_array($assertion['values'] ?? null)) {
                foreach ($assertion['values'] as $value) {
                    if (is_int($value)) {
                        $statuses[] = $value;
                    }
                }
            }
        }

        return $statuses;
    }

    private function key(string $method, string $path): string
    {
        return strtoupper($method).' '.$path;
    }
}
