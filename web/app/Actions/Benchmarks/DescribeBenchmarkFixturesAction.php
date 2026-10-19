<?php

namespace App\Actions\Benchmarks;

use App\Exceptions\ApiException;
use App\Services\Benchmark\GroundTruthLoader;

/**
 * M14 (read only): the ADR-0006 experiment fixture of every ground-truth.yaml on disk — role names
 * and per-operation roles of the auth fixture (never the tokens) and the business rules that new
 * experiments will snapshot.
 */
class DescribeBenchmarkFixturesAction
{
    public function __construct(private readonly GroundTruthLoader $loader) {}

    /**
     * @return array<string, array{auth: array<string, mixed>|null, business_rules: list<array{code: string, description: string, method: string|null, path: string|null}>, error: string|null}>
     */
    public function execute(): array
    {
        $fixtures = [];
        foreach ($this->loader->availableSlugs() as $slug) {
            try {
                $groundTruth = $this->loader->load($slug);
                $fixtures[$slug] = [
                    'auth' => $groundTruth->auth?->toPublicArray(),
                    'business_rules' => $groundTruth->businessRules,
                    'error' => null,
                ];
            } catch (ApiException $e) {
                $fixtures[$slug] = ['auth' => null, 'business_rules' => [], 'error' => $e->getMessage()];
            }
        }

        return $fixtures;
    }
}
