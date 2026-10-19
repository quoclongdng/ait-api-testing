<?php

namespace Tests\Feature\Services\TestEngine;

use App\Enums\TestCaseStatus;
use App\Models\Endpoint;
use App\Models\TestCase;
use App\Models\TestSuite;
use App\Services\TestEngine\TestDeduplicationService;
use App\Services\TestEngine\TestFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase as BaseTestCase;

class TestDeduplicationServiceTest extends BaseTestCase
{
    use RefreshDatabase;

    protected TestDeduplicationService $service;

    protected TestFingerprintService $fingerprintService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fingerprintService = new TestFingerprintService;
        $this->service = new TestDeduplicationService($this->fingerprintService);
    }

    public function test_intra_batch_deduplication_marks_subsequent_duplicate_cases(): void
    {
        $suite = TestSuite::factory()->create();

        $baseDsl = [
            'dsl_version' => '1.0',
            'endpoint' => ['method' => 'GET', 'path' => '/items/1'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Fetch item',
                    'request' => ['method' => 'GET', 'path' => '/items/1'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $case1 = [
            'title' => 'First unique case',
            'dsl' => array_merge($baseDsl, ['title' => 'First unique case']),
        ];

        $case2 = [
            'title' => 'Duplicate case with different title',
            'dsl' => array_merge($baseDsl, ['title' => 'Duplicate case with different title']),
        ];

        $case3 = [
            'title' => 'Second unique case',
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => 'Second unique case',
                'endpoint' => ['method' => 'POST', 'path' => '/items'],
                'technique' => 'HAPPY_PATH',
                'source' => 'RULE',
                'steps' => [
                    [
                        'name' => 'Create item',
                        'request' => ['method' => 'POST', 'path' => '/items'],
                        'assertions' => [['type' => 'status', 'equals' => 201]],
                    ],
                ],
            ],
        ];

        $result = $this->service->deduplicateBatch([$case1, $case2, $case3], $suite->id, checkDatabase: false);

        $this->assertSame(3, $result->total);
        $this->assertSame(2, $result->uniqueCount);
        $this->assertSame(1, $result->duplicateCount);
        $this->assertEqualsWithDelta(0.3333, $result->redundancyRate, 0.001);
        $this->assertTrue($result->hasDuplicates());

        $this->assertSame(TestCaseStatus::Valid, $result->processed[0]['status']);
        $this->assertSame(TestCaseStatus::Duplicate, $result->processed[1]['status']);
        $this->assertSame(TestCaseStatus::Valid, $result->processed[2]['status']);

        $this->assertNotEmpty($result->duplicates);
        $this->assertSame('Duplicate case with different title', $result->duplicates[0]['title']);
    }

    public function test_deduplication_against_existing_database_records(): void
    {
        $suite = TestSuite::factory()->create();
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $suite->api_spec_id]);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Existing test case in DB',
            'endpoint' => ['method' => 'GET', 'path' => '/profile'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'get profile',
                    'request' => ['method' => 'GET', 'path' => '/profile'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $fingerprint = $this->fingerprintService->calculate($dsl);

        // Seed existing test case in DB
        $existing = TestCase::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'fingerprint' => $fingerprint,
            'status' => TestCaseStatus::Valid,
            'dsl' => $dsl,
        ]);

        // Incoming new case with different title but identical execution
        $incomingCase = [
            'title' => 'Brand new generated test case with same logic',
            'dsl' => array_merge($dsl, ['title' => 'Brand new generated test case with same logic']),
        ];

        $result = $this->service->deduplicateBatch([$incomingCase], $suite->id, checkDatabase: true);

        $this->assertSame(1, $result->total);
        $this->assertSame(0, $result->uniqueCount);
        $this->assertSame(1, $result->duplicateCount);
        $this->assertSame(1.0, $result->redundancyRate);
        $this->assertSame(TestCaseStatus::Duplicate, $result->processed[0]['status']);

        // Check findDuplicate method
        $found = $this->service->findDuplicate($incomingCase['dsl'], $suite->id);
        $this->assertNotNull($found);
        $this->assertSame($existing->id, $found->id);

        $this->assertTrue($this->service->isDuplicate($incomingCase['dsl'], $suite->id));
    }

    public function test_same_test_case_in_different_suites_is_not_flagged_as_duplicate(): void
    {
        $suite1 = TestSuite::factory()->create();
        $suite2 = TestSuite::factory()->create();

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Common test',
            'endpoint' => ['method' => 'GET', 'path' => '/status'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'check status',
                    'request' => ['method' => 'GET', 'path' => '/status'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $fingerprint = $this->fingerprintService->calculate($dsl);

        // Store in Suite 1
        TestCase::factory()->create([
            'test_suite_id' => $suite1->id,
            'fingerprint' => $fingerprint,
            'dsl' => $dsl,
        ]);

        // Evaluate incoming case for Suite 2
        $incomingCase = ['title' => 'Common test', 'dsl' => $dsl];
        $result = $this->service->deduplicateBatch([$incomingCase], $suite2->id, checkDatabase: true);

        $this->assertSame(1, $result->uniqueCount);
        $this->assertSame(0, $result->duplicateCount);
        $this->assertFalse($result->hasDuplicates());
        $this->assertFalse($this->service->isDuplicate($dsl, $suite2->id));
    }

    public function test_find_duplicate_can_exclude_current_test_case_id(): void
    {
        $suite = TestSuite::factory()->create();

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Updating test case',
            'endpoint' => ['method' => 'GET', 'path' => '/info'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'read info',
                    'request' => ['method' => 'GET', 'path' => '/info'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $fingerprint = $this->fingerprintService->calculate($dsl);

        $case = TestCase::factory()->create([
            'test_suite_id' => $suite->id,
            'fingerprint' => $fingerprint,
            'dsl' => $dsl,
        ]);

        // When excluding self, should return null (not duplicate with itself)
        $this->assertNull($this->service->findDuplicate($dsl, $suite->id, excludeTestCaseId: $case->id));
        $this->assertFalse($this->service->isDuplicate($dsl, $suite->id, excludeTestCaseId: $case->id));

        // When not excluding self, returns self
        $this->assertNotNull($this->service->findDuplicate($dsl, $suite->id));
        $this->assertTrue($this->service->isDuplicate($dsl, $suite->id));
    }

    public function test_batch_with_persisted_test_cases_does_not_self_duplicate(): void
    {
        $suite = TestSuite::factory()->create();
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $suite->api_spec_id]);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Persisted case',
            'endpoint' => ['method' => 'GET', 'path' => '/persisted'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step',
                    'request' => ['method' => 'GET', 'path' => '/persisted'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $fingerprint = $this->fingerprintService->calculate($dsl);

        $persistedCase = TestCase::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'fingerprint' => $fingerprint,
            'status' => TestCaseStatus::Valid,
            'dsl' => $dsl,
        ]);

        // When evaluating the persisted model in batch with DB check enabled, it must not match itself
        $result = $this->service->deduplicateBatch([$persistedCase], $suite->id, checkDatabase: true);

        $this->assertSame(1, $result->total);
        $this->assertSame(1, $result->uniqueCount);
        $this->assertSame(0, $result->duplicateCount);
        $this->assertFalse($result->hasDuplicates());
        $this->assertSame(TestCaseStatus::Valid, $result->processed[0]['status']);

        // When two persisted cases with same fingerprint are passed in batch:
        // First is kept unique, second is marked duplicate of the first
        $persistedCase2 = TestCase::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'fingerprint' => $fingerprint,
            'status' => TestCaseStatus::Valid,
            'dsl' => $dsl,
        ]);

        $resultBatch = $this->service->deduplicateBatch([$persistedCase, $persistedCase2], $suite->id, checkDatabase: true);
        $this->assertSame(2, $resultBatch->total);
        $this->assertSame(1, $resultBatch->uniqueCount);
        $this->assertSame(1, $resultBatch->duplicateCount);
        $this->assertSame(TestCaseStatus::Valid, $resultBatch->processed[0]['status']);
        $this->assertSame(TestCaseStatus::Duplicate, $resultBatch->processed[1]['status']);
    }

    public function test_persisted_test_cases_with_empty_object_and_empty_array_preserve_fingerprint_through_batch(): void
    {
        $suite = TestSuite::factory()->create();
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $suite->api_spec_id]);

        $rawDslObj = json_encode([
            'dsl_version' => '1.0',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'title' => 'Case with empty object body',
            'steps' => [
                [
                    'name' => 'create item',
                    'request' => ['method' => 'POST', 'path' => '/items', 'body' => new \stdClass],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $rawDslArr = json_encode([
            'dsl_version' => '1.0',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'title' => 'Case with empty array body',
            'steps' => [
                [
                    'name' => 'create item',
                    'request' => ['method' => 'POST', 'path' => '/items', 'body' => []],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $fpObj = $this->fingerprintService->calculate($rawDslObj);
        $fpArr = $this->fingerprintService->calculate($rawDslArr);

        // Confirm the two fingerprints are strictly distinct
        $this->assertNotSame($fpObj, $fpArr);

        // Persist both TestCase records to database preserving their raw JSON strings
        $caseObj = TestCase::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'title' => 'Case with empty object body',
            'fingerprint' => $fpObj,
            'status' => TestCaseStatus::Valid,
        ]);
        $caseObj->setRawAttributes(array_merge($caseObj->getAttributes(), ['dsl' => $rawDslObj]));
        $caseObj->save();

        $caseArr = TestCase::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'title' => 'Case with empty array body',
            'fingerprint' => $fpArr,
            'status' => TestCaseStatus::Valid,
        ]);
        $caseArr->setRawAttributes(array_merge($caseArr->getAttributes(), ['dsl' => $rawDslArr]));
        $caseArr->save();

        // 1. Check calculate($model) directly matches expected fingerprints
        $this->assertSame($fpObj, $this->fingerprintService->calculate($caseObj));
        $this->assertSame($fpArr, $this->fingerprintService->calculate($caseArr));

        // 2. Check individual batch deduplication keeps exact fingerprint unchanged
        $resultSingleObj = $this->service->deduplicateBatch([$caseObj], $suite->id, checkDatabase: true);
        $this->assertSame(1, $resultSingleObj->uniqueCount);
        $this->assertSame(0, $resultSingleObj->duplicateCount);
        $this->assertSame($fpObj, $resultSingleObj->processed[0]['fingerprint']);

        $resultSingleArr = $this->service->deduplicateBatch([$caseArr], $suite->id, checkDatabase: true);
        $this->assertSame(1, $resultSingleArr->uniqueCount);
        $this->assertSame(0, $resultSingleArr->duplicateCount);
        $this->assertSame($fpArr, $resultSingleArr->processed[0]['fingerprint']);

        // 3. Check combined batch containing both persisted models:
        // Neither must match the other, and fingerprints must remain unchanged through batch
        $batchResult = $this->service->deduplicateBatch([$caseObj, $caseArr], $suite->id, checkDatabase: true);
        $this->assertSame(2, $batchResult->total);
        $this->assertSame(2, $batchResult->uniqueCount);
        $this->assertSame(0, $batchResult->duplicateCount);
        $this->assertFalse($batchResult->hasDuplicates());
        $this->assertSame($fpObj, $batchResult->processed[0]['fingerprint']);
        $this->assertSame($fpArr, $batchResult->processed[1]['fingerprint']);
        $this->assertSame(TestCaseStatus::Valid, $batchResult->processed[0]['status']);
        $this->assertSame(TestCaseStatus::Valid, $batchResult->processed[1]['status']);
    }

    public function test_regression_incoming_array_with_forged_or_stale_id_does_not_bypass_database_dedup(): void
    {
        $suite = TestSuite::factory()->create();
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $suite->api_spec_id]);

        $baseDsl = [
            'dsl_version' => '1.0',
            'title' => 'Original persisted test case',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'fetch items',
                    'request' => ['method' => 'GET', 'path' => '/items'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $fingerprint = $this->fingerprintService->calculate($baseDsl);

        $existingCase = TestCase::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'fingerprint' => $fingerprint,
            'status' => TestCaseStatus::Valid,
            'dsl' => $baseDsl,
        ]);

        $incomingArray = [
            'id' => $existingCase->id,
            'status' => 'VALID',
            'dsl' => array_merge($baseDsl, ['title' => 'Forged or stale ID incoming case with same execution content']),
        ];

        $result = $this->service->deduplicateBatch([$incomingArray], $suite->id, checkDatabase: true);

        $this->assertSame(1, $result->total);
        $this->assertSame(0, $result->uniqueCount);
        $this->assertSame(1, $result->duplicateCount);
        $this->assertTrue($result->hasDuplicates());
        $this->assertSame(TestCaseStatus::Duplicate, $result->processed[0]['status']);
    }

    public function test_regression_persisted_model_self_update_excludes_itself_from_database_lookup(): void
    {
        $suite = TestSuite::factory()->create();
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $suite->api_spec_id]);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Case to update',
            'endpoint' => ['method' => 'GET', 'path' => '/profile'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'fetch profile',
                    'request' => ['method' => 'GET', 'path' => '/profile'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $fingerprint = $this->fingerprintService->calculate($dsl);

        $persistedCase = TestCase::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'fingerprint' => $fingerprint,
            'status' => TestCaseStatus::Valid,
            'dsl' => $dsl,
        ]);

        $resultModel = $this->service->deduplicateBatch([$persistedCase], $suite->id, checkDatabase: true);
        $this->assertSame(1, $resultModel->total);
        $this->assertSame(1, $resultModel->uniqueCount);
        $this->assertSame(0, $resultModel->duplicateCount);
        $this->assertFalse($resultModel->hasDuplicates());
        $this->assertSame(TestCaseStatus::Valid, $resultModel->processed[0]['status']);

        $incomingArray = [
            'status' => 'VALID',
            'dsl' => $dsl,
        ];

        $resultExplicit = $this->service->deduplicateBatch(
            [$incomingArray],
            $suite->id,
            checkDatabase: true,
            excludeTestCaseIds: [$persistedCase->id]
        );
        $this->assertSame(1, $resultExplicit->total);
        $this->assertSame(1, $resultExplicit->uniqueCount);
        $this->assertSame(0, $resultExplicit->duplicateCount);
        $this->assertFalse($resultExplicit->hasDuplicates());
        $this->assertSame(TestCaseStatus::Valid, $resultExplicit->processed[0]['status']);
    }

    public function test_regression_different_suite_does_not_collide_in_database_dedup(): void
    {
        $suite1 = TestSuite::factory()->create();
        $suite2 = TestSuite::factory()->create();
        $endpoint1 = Endpoint::factory()->create(['api_spec_id' => $suite1->api_spec_id]);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Cross suite test',
            'endpoint' => ['method' => 'GET', 'path' => '/shared-route'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'shared step',
                    'request' => ['method' => 'GET', 'path' => '/shared-route'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $fingerprint = $this->fingerprintService->calculate($dsl);

        TestCase::factory()->create([
            'test_suite_id' => $suite1->id,
            'endpoint_id' => $endpoint1->id,
            'fingerprint' => $fingerprint,
            'status' => TestCaseStatus::Valid,
            'dsl' => $dsl,
        ]);

        $incomingCase = ['dsl' => $dsl];
        $result = $this->service->deduplicateBatch([$incomingCase], $suite2->id, checkDatabase: true);

        $this->assertSame(1, $result->total);
        $this->assertSame(1, $result->uniqueCount);
        $this->assertSame(0, $result->duplicateCount);
        $this->assertSame(TestCaseStatus::Valid, $result->processed[0]['status']);
    }

    public function test_regression_ordinary_incoming_case_without_id_detected_as_duplicate(): void
    {
        $suite = TestSuite::factory()->create();
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $suite->api_spec_id]);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Ordinary DB duplicate',
            'endpoint' => ['method' => 'POST', 'path' => '/orders'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'create order',
                    'request' => ['method' => 'POST', 'path' => '/orders'],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $fingerprint = $this->fingerprintService->calculate($dsl);

        TestCase::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'fingerprint' => $fingerprint,
            'status' => TestCaseStatus::Valid,
            'dsl' => $dsl,
        ]);

        $incomingCase = [
            'title' => 'Incoming ordinary duplicate without id',
            'dsl' => array_merge($dsl, ['title' => 'Different title']),
        ];

        $result = $this->service->deduplicateBatch([$incomingCase], $suite->id, checkDatabase: true);

        $this->assertSame(1, $result->total);
        $this->assertSame(0, $result->uniqueCount);
        $this->assertSame(1, $result->duplicateCount);
        $this->assertTrue($result->hasDuplicates());
        $this->assertSame(TestCaseStatus::Duplicate, $result->processed[0]['status']);
    }
}
