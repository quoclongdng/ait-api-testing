<?php

namespace App\Services\Generation;

use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\TestCase;
use App\Services\Dsl\DslValidator;
use stdClass;
use Throwable;

/**
 * Supplies RULE cases as `seed_cases` for a HYBRID generation job (docs/DESIGN.md §10.2).
 *
 * Prefers the suite's existing VALID/APPROVED RULE cases of each target endpoint; every target
 * endpoint without such a case gets seeds generated in memory with the RuleGenerator (never
 * persisted here), so a partially seeded suite still seeds every selected endpoint.
 */
class HybridSeedCaseProvider
{
    public const MAX_SEED_CASES = 20;

    public const MAX_SEED_CASES_PER_ENDPOINT = 3;

    /**
     * DSL keys whose value is a JSON object even when empty (test-dsl.schema.json). Only applied to
     * in-memory RuleGenerator output; `extract` is an array in the DSL and must stay `[]`.
     */
    private const OBJECT_KEYS = ['variables', 'path_params', 'query', 'headers', 'schema', 'properties'];

    public function __construct(
        protected RuleGenerator $generator,
        protected DslValidator $validator,
    ) {}

    /**
     * @param  list<int>  $targetEndpointIds
     * @return list<array<string, mixed>>
     */
    public function forJob(GenerationJob $job, array $targetEndpointIds): array
    {
        if ($targetEndpointIds === []) {
            return [];
        }

        $seeds = $this->fromSuite($job, $targetEndpointIds);
        $unseeded = array_values(array_diff($targetEndpointIds, array_keys($seeds)));
        if ($unseeded !== []) {
            $seeds += $this->fromRuleGenerator($job, $unseeded);
        }

        // Round-robin over endpoints (in target order) so the global cap leaves no endpoint out first.
        $ordered = [];
        foreach ($targetEndpointIds as $endpointId) {
            if (isset($seeds[$endpointId])) {
                $ordered[$endpointId] = $seeds[$endpointId];
            }
        }
        $seeds = [];
        for ($round = 0; $round < self::MAX_SEED_CASES_PER_ENDPOINT; $round++) {
            foreach ($ordered as $dsls) {
                if (isset($dsls[$round]) && count($seeds) < self::MAX_SEED_CASES) {
                    $seeds[] = $dsls[$round];
                }
            }
        }

        return $seeds;
    }

    /**
     * @param  list<int>  $targetEndpointIds
     * @return array<int, list<array<string, mixed>>> Seeds keyed by endpoint id.
     */
    private function fromSuite(GenerationJob $job, array $targetEndpointIds): array
    {
        $cases = TestCase::query()
            ->where('test_suite_id', $job->test_suite_id)
            ->where('source', TestCaseSource::Rule)
            ->whereIn('status', [TestCaseStatus::Valid, TestCaseStatus::Approved])
            ->whereIn('endpoint_id', $targetEndpointIds)
            ->orderBy('id')
            ->get(['id', 'endpoint_id', 'dsl']);

        $seeds = [];
        foreach ($cases as $case) {
            if (count($seeds[$case->endpoint_id] ?? []) >= self::MAX_SEED_CASES_PER_ENDPOINT) {
                continue;
            }
            $seeds[$case->endpoint_id][] = $this->objectPreservingDsl($case);
        }

        return $seeds;
    }

    /**
     * The `array` cast decodes every `{}` (e.g. a free-form property schema `metadata: {}`,
     * `items: {}`, `additionalProperties: {}`) as `[]`, which ai-service then rejects as an invalid
     * inline JSON Schema. Decode the raw column with objects instead, as ExecuteTestRunAction does;
     * only the top level becomes an array, every nested JSON object stays a stdClass.
     *
     * @return array<string, mixed>
     */
    private function objectPreservingDsl(TestCase $case): array
    {
        $raw = $case->getRawOriginal('dsl');
        if (is_string($raw) && $raw !== '') {
            $decoded = $this->objectsToArrays(json_decode($raw, false));
            if (is_array($decoded) && ! array_is_list($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return $this->restoreObjects($case->dsl);
    }

    /**
     * Turns decoded JSON objects back into associative arrays (the shape every seed consumer
     * reads), except where the array would re-encode as something else: an empty object stays
     * `{}` and an object whose keys look like a list (`{"0": ...}`) stays a stdClass.
     */
    private function objectsToArrays(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->objectsToArrays($item), $value);
        }
        if (! $value instanceof stdClass) {
            return $value;
        }

        $vars = array_map(fn (mixed $item): mixed => $this->objectsToArrays($item), get_object_vars($value));

        return $vars === [] || array_is_list($vars) ? (object) $vars : $vars;
    }

    /**
     * @param  list<int>  $targetEndpointIds
     * @return array<int, list<array<string, mixed>>> Seeds keyed by endpoint id.
     */
    private function fromRuleGenerator(GenerationJob $job, array $targetEndpointIds): array
    {
        $job->loadMissing('apiSpec.endpoints');
        $apiSpec = $job->apiSpec;
        $normalized = $apiSpec->normalized;
        $componentsSchemas = is_array($normalized)
            && isset($normalized['components']['schemas'])
            && is_array($normalized['components']['schemas'])
            ? $normalized['components']['schemas']
            : [];

        $seeds = [];
        /** @var Endpoint $endpoint */
        // Spec (id) order, not PostgreSQL heap order of the unordered relation (S13-03).
        foreach ($apiSpec->endpoints->whereIn('id', $targetEndpointIds)->sortBy('id') as $endpoint) {
            try {
                $cases = $this->generator->generate(
                    $endpoint,
                    [TestTechnique::HappyPath, TestTechnique::MissingRequired, TestTechnique::Boundary],
                    $componentsSchemas,
                    $job->authContext(),
                );
            } catch (Throwable) {
                continue;
            }

            foreach ($cases as $dsl) {
                if (count($seeds[$endpoint->id] ?? []) >= self::MAX_SEED_CASES_PER_ENDPOINT) {
                    break;
                }
                if ($this->validator->validate($dsl, $apiSpec, $job->contextVariables())->isValid) {
                    $seeds[$endpoint->id][] = $this->restoreObjects($dsl);
                }
            }
        }

        return $seeds;
    }

    /**
     * jsonb decoding turns `{}` into `[]`; restore empty objects so the payload still matches the DSL schema.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function restoreObjects(array $node): array
    {
        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if ($value === [] && is_string($key) && in_array($key, self::OBJECT_KEYS, true)) {
                $node[$key] = new stdClass;

                continue;
            }
            $node[$key] = $this->restoreObjects($value);
        }

        return $node;
    }
}
