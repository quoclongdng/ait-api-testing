<?php

namespace Tests\Unit\Services\TestEngine;

use App\Enums\TestCaseStatus;
use App\Services\TestEngine\DeduplicationResult;
use App\Services\TestEngine\TestDeduplicationService;
use App\Services\TestEngine\TestFingerprintService;
use PHPUnit\Framework\TestCase;

class TestDeduplicationServiceTest extends TestCase
{
    protected TestDeduplicationService $service;

    protected TestFingerprintService $fingerprintService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fingerprintService = new TestFingerprintService;
        $this->service = new TestDeduplicationService($this->fingerprintService);
    }

    public function test_batch_deduplication_flags_duplicate_dsl_with_different_titles(): void
    {
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

        $result = $this->service->deduplicateBatch([$case1, $case2], testSuiteId: 1, checkDatabase: false);

        $this->assertSame(2, $result->total);
        $this->assertSame(1, $result->uniqueCount);
        $this->assertSame(1, $result->duplicateCount);
        $this->assertSame(0.5, $result->redundancyRate);
        $this->assertTrue($result->hasDuplicates());

        $this->assertSame(TestCaseStatus::Valid, $result->processed[0]['status']);
        $this->assertSame(TestCaseStatus::Duplicate, $result->processed[1]['status']);
        $this->assertSame($result->processed[0]['fingerprint'], $result->processed[1]['fingerprint']);
    }

    public function test_batch_deduplication_keeps_distinct_dsl_as_valid(): void
    {
        $case1 = [
            'title' => 'Get item',
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => 'Get item',
                'endpoint' => ['method' => 'GET', 'path' => '/items'],
                'technique' => 'HAPPY_PATH',
                'source' => 'RULE',
                'steps' => [
                    [
                        'name' => 'get',
                        'request' => ['method' => 'GET', 'path' => '/items'],
                        'assertions' => [['type' => 'status', 'equals' => 200]],
                    ],
                ],
            ],
        ];

        $case2 = [
            'title' => 'Create item',
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => 'Create item',
                'endpoint' => ['method' => 'POST', 'path' => '/items'],
                'technique' => 'HAPPY_PATH',
                'source' => 'RULE',
                'steps' => [
                    [
                        'name' => 'create',
                        'request' => ['method' => 'POST', 'path' => '/items'],
                        'assertions' => [['type' => 'status', 'equals' => 201]],
                    ],
                ],
            ],
        ];

        $result = $this->service->deduplicateBatch([$case1, $case2], testSuiteId: 1, checkDatabase: false);

        $this->assertSame(2, $result->total);
        $this->assertSame(2, $result->uniqueCount);
        $this->assertSame(0, $result->duplicateCount);
        $this->assertSame(0.0, $result->redundancyRate);
        $this->assertFalse($result->hasDuplicates());

        $this->assertSame(TestCaseStatus::Valid, $result->processed[0]['status']);
        $this->assertSame(TestCaseStatus::Valid, $result->processed[1]['status']);
        $this->assertNotSame($result->processed[0]['fingerprint'], $result->processed[1]['fingerprint']);
    }

    public function test_empty_batch_returns_zero_counts_and_zero_rate(): void
    {
        $result = $this->service->deduplicateBatch([], testSuiteId: 1, checkDatabase: false);

        $this->assertSame(0, $result->total);
        $this->assertSame(0, $result->uniqueCount);
        $this->assertSame(0, $result->duplicateCount);
        $this->assertSame(0.0, $result->redundancyRate);
        $this->assertFalse($result->hasDuplicates());
        $this->assertSame([], $result->processed);
    }

    public function test_batch_supports_raw_dsl_items_without_dsl_wrapper_key(): void
    {
        $dsl1 = [
            'dsl_version' => '1.0',
            'title' => 'Direct DSL 1',
            'endpoint' => ['method' => 'GET', 'path' => '/users'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'get users',
                    'request' => ['method' => 'GET', 'path' => '/users'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $dsl2 = $dsl1;
        $dsl2['title'] = 'Direct DSL 2 (Different title)';

        $result = $this->service->deduplicateBatch([$dsl1, $dsl2], testSuiteId: 1, checkDatabase: false);

        $this->assertSame(2, $result->total);
        $this->assertSame(1, $result->uniqueCount);
        $this->assertSame(1, $result->duplicateCount);
        $this->assertTrue($result->hasDuplicates());
    }

    public function test_deduplication_result_to_array_serialization(): void
    {
        $result = new DeduplicationResult(
            total: 10,
            uniqueCount: 7,
            duplicateCount: 3,
            redundancyRate: 0.3,
            processed: [['status' => TestCaseStatus::Valid]],
            uniques: [['status' => TestCaseStatus::Valid]],
            duplicates: [],
        );

        $array = $result->toArray();

        $this->assertSame(10, $array['total']);
        $this->assertSame(7, $array['unique_count']);
        $this->assertSame(3, $array['duplicate_count']);
        $this->assertSame(0.3, $array['redundancy_rate']);
        $this->assertArrayHasKey('processed', $array);
        $this->assertArrayHasKey('uniques', $array);
        $this->assertArrayHasKey('duplicates', $array);
    }

    public function test_get_fingerprint_service_instance(): void
    {
        $this->assertSame($this->fingerprintService, $this->service->getFingerprintService());
    }

    public function test_regression_invalid_item_with_uncanonicalizable_dsl_does_not_halt_batch(): void
    {
        $invalidItem = [
            'title' => 'Broken DSL Item',
            'dsl' => '{invalid json string',
            'status' => 'INVALID',
        ];

        $validItem = [
            'title' => 'Valid Item',
            'dsl' => [
                'dsl_version' => '1.0',
                'endpoint' => ['method' => 'GET', 'path' => '/health'],
                'technique' => 'HAPPY_PATH',
                'source' => 'RULE',
                'steps' => [
                    [
                        'name' => 'ping',
                        'request' => ['method' => 'GET', 'path' => '/health'],
                        'assertions' => [['type' => 'status', 'equals' => 200]],
                    ],
                ],
            ],
        ];

        $result = $this->service->deduplicateBatch([$invalidItem, $validItem], testSuiteId: 1, checkDatabase: false);

        $this->assertSame(2, $result->total);
        $this->assertSame(1, $result->uniqueCount);
        $this->assertSame(0, $result->duplicateCount);

        $this->assertSame(TestCaseStatus::Invalid, $result->processed[0]['status']);
        $this->assertNull($result->processed[0]['fingerprint']);
        $this->assertNotEmpty($result->processed[0]['validation_errors']);

        $this->assertSame(TestCaseStatus::Valid, $result->processed[1]['status']);
        $this->assertNotNull($result->processed[1]['fingerprint']);
    }

    public function test_regression_string_duplicate_status_is_reset_to_valid_for_unique_item(): void
    {
        $uniqueItemWithStringStatus = [
            'title' => 'Previously marked duplicate item',
            'status' => 'DUPLICATE',
            'dsl' => [
                'dsl_version' => '1.0',
                'endpoint' => ['method' => 'GET', 'path' => '/unique-path'],
                'technique' => 'HAPPY_PATH',
                'source' => 'RULE',
                'steps' => [
                    [
                        'name' => 'unique',
                        'request' => ['method' => 'GET', 'path' => '/unique-path'],
                        'assertions' => [['type' => 'status', 'equals' => 200]],
                    ],
                ],
            ],
        ];

        $result = $this->service->deduplicateBatch([$uniqueItemWithStringStatus], testSuiteId: 1, checkDatabase: false);

        $this->assertSame(1, $result->total);
        $this->assertSame(1, $result->uniqueCount);
        $this->assertSame(0, $result->duplicateCount);
        $this->assertSame(TestCaseStatus::Valid, $result->processed[0]['status']);
        $this->assertSame(TestCaseStatus::Valid, $result->uniques[0]['status']);

        $uniqueItemWithEnumStatus = $uniqueItemWithStringStatus;
        $uniqueItemWithEnumStatus['status'] = TestCaseStatus::Duplicate;

        $resultEnum = $this->service->deduplicateBatch([$uniqueItemWithEnumStatus], testSuiteId: 1, checkDatabase: false);
        $this->assertSame(TestCaseStatus::Valid, $resultEnum->processed[0]['status']);
    }
}
