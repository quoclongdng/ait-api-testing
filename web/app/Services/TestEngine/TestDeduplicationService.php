<?php

namespace App\Services\TestEngine;

use App\Enums\TestCaseStatus;
use App\Models\TestCase;

class TestDeduplicationService
{
    /**
     * Statuses of the suite's "existing" cases that a new case is deduplicated against (§10.5): the
     * executable cases only. A DUPLICATE row only copies another case's fingerprint and an INVALID
     * or ARCHIVED case is never run, so content that is no longer in any VALID/APPROVED case (its
     * original was edited or archived) is new again. ai-service applies the same rule: its known
     * fingerprints are the HYBRID seed cases, which are VALID/APPROVED cases or fresh RULE output.
     *
     * @var list<TestCaseStatus>
     */
    public const EXISTING_STATUSES = [TestCaseStatus::Valid, TestCaseStatus::Approved];

    /**
     * Fingerprints of the suite's existing (VALID/APPROVED) cases, as a set.
     *
     * @param  list<int>  $excludeTestCaseIds
     * @return array<string, true>
     */
    public function existingFingerprints(int $testSuiteId, array $excludeTestCaseIds = []): array
    {
        $query = TestCase::query()
            ->where('test_suite_id', $testSuiteId)
            ->whereIn('status', self::existingStatusValues())
            ->whereNotNull('fingerprint');
        if ($excludeTestCaseIds !== []) {
            $query->whereNotIn('id', array_values(array_unique($excludeTestCaseIds)));
        }

        /** @var list<string> $fingerprints */
        $fingerprints = $query->pluck('fingerprint')->filter(static fn (mixed $f): bool => is_string($f))->values()->all();

        return array_fill_keys($fingerprints, true);
    }

    /**
     * @return list<string>
     */
    public static function existingStatusValues(): array
    {
        return array_map(static fn (TestCaseStatus $status): string => $status->value, self::EXISTING_STATUSES);
    }

    public function __construct(
        protected TestFingerprintService $fingerprintService,
    ) {}

    /**
     * Deduplicate a batch of test cases for a given test suite.
     * Evaluates both against the suite's existing VALID/APPROVED cases and within the current batch.
     *
     * @param  list<array<string, mixed>|TestCase>  $items
     * @param  list<int>|null  $excludeTestCaseIds  Explicit persisted TestCase IDs to exclude (caller-verified).
     */
    public function deduplicateBatch(
        array $items,
        int $testSuiteId,
        bool $checkDatabase = true,
        ?array $excludeTestCaseIds = null,
    ): DeduplicationResult {
        /** @var array<string, bool> $knownFingerprints */
        $knownFingerprints = [];

        if ($checkDatabase) {
            /** @var list<int> $excludedIds */
            $excludedIds = $excludeTestCaseIds ?? [];
            foreach ($items as $item) {
                if ($item instanceof TestCase && $item->exists) {
                    $excludedIds[] = (int) $item->id;
                }
            }

            $knownFingerprints = $this->existingFingerprints($testSuiteId, $excludedIds);
        }

        /** @var array<string, bool> $batchSeenFingerprints */
        $batchSeenFingerprints = [];

        $processed = [];
        $uniques = [];
        $duplicates = [];

        foreach ($items as $item) {
            $record = $this->normalizeItemToArray($item);

            $statusRaw = $record['status'] ?? null;
            $statusEnum = $statusRaw instanceof TestCaseStatus
                ? $statusRaw
                : (is_string($statusRaw) ? (TestCaseStatus::tryFrom(strtoupper($statusRaw)) ?? TestCaseStatus::tryFrom($statusRaw)) : null);

            $isExplicitlyInvalid = ($statusEnum === TestCaseStatus::Invalid);

            $fingerprint = null;
            $calculationError = null;
            try {
                $fingerprint = $this->fingerprintService->calculate($item instanceof TestCase ? $item : $record['dsl']);
                $record['fingerprint'] = $fingerprint;
            } catch (\InvalidArgumentException $e) {
                $calculationError = $e->getMessage();
                $record['fingerprint'] = null;
            }

            if ($calculationError !== null || $isExplicitlyInvalid) {
                if ($calculationError !== null) {
                    $errors = $record['validation_errors'] ?? [];
                    if (! is_array($errors)) {
                        $errors = [$errors];
                    }
                    $errors[] = $calculationError;
                    $record['validation_errors'] = $errors;
                }
                $record['status'] = TestCaseStatus::Invalid;
                $processed[] = $record;

                continue;
            }

            $isDuplicate = isset($knownFingerprints[$fingerprint]) || isset($batchSeenFingerprints[$fingerprint]);

            if ($isDuplicate) {
                $record['status'] = TestCaseStatus::Duplicate;
                $duplicates[] = $record;
            } else {
                $batchSeenFingerprints[$fingerprint] = true;
                if ($statusEnum === null || $statusEnum === TestCaseStatus::Duplicate) {
                    $record['status'] = TestCaseStatus::Valid;
                } else {
                    $record['status'] = $statusEnum;
                }
                $uniques[] = $record;
            }

            $processed[] = $record;
        }

        $total = count($items);
        $duplicateCount = count($duplicates);
        $uniqueCount = count($uniques);
        $redundancyRate = $total > 0 ? round($duplicateCount / $total, 4) : 0.0;

        return new DeduplicationResult(
            total: $total,
            uniqueCount: $uniqueCount,
            duplicateCount: $duplicateCount,
            redundancyRate: $redundancyRate,
            processed: $processed,
            uniques: $uniques,
            duplicates: $duplicates,
        );
    }

    /**
     * Check whether a single Test DSL would collide with an existing test case in the suite.
     *
     * @param  array<string, mixed>|string|TestCase  $dsl
     */
    public function isDuplicate(array|string|TestCase $dsl, int $testSuiteId, ?int $excludeTestCaseId = null): bool
    {
        return $this->findDuplicate($dsl, $testSuiteId, $excludeTestCaseId) !== null;
    }

    /**
     * Find an existing test case in the test suite that has the same fingerprint.
     *
     * @param  array<string, mixed>|string|TestCase  $dsl
     */
    public function findDuplicate(array|string|TestCase $dsl, int $testSuiteId, ?int $excludeTestCaseId = null): ?TestCase
    {
        try {
            $fingerprint = $this->fingerprintService->calculate($dsl);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findByFingerprint($fingerprint, $testSuiteId, $excludeTestCaseId);
    }

    /**
     * Find an existing test case by pre-calculated fingerprint.
     *
     * When $statuses is given, only cases in one of those statuses are considered (e.g. the manual-edit path
     * ignores DUPLICATE rows, which carry the same fingerprint as their original). The oldest match wins.
     *
     * @param  list<TestCaseStatus>|null  $statuses
     */
    public function findByFingerprint(string $fingerprint, int $testSuiteId, ?int $excludeTestCaseId = null, ?array $statuses = null): ?TestCase
    {
        $query = TestCase::query()
            ->where('test_suite_id', $testSuiteId)
            ->where('fingerprint', $fingerprint);

        if ($excludeTestCaseId !== null) {
            $query->where('id', '!=', $excludeTestCaseId);
        }

        if ($statuses !== null) {
            $query->whereIn('status', array_map(static fn (TestCaseStatus $status): string => $status->value, $statuses));
        }

        return $query->orderBy('id')->first();
    }

    /**
     * Get the underlying fingerprint service.
     */
    public function getFingerprintService(): TestFingerprintService
    {
        return $this->fingerprintService;
    }

    /**
     * Helper to extract/normalize input item to array.
     *
     * @param  array<string, mixed>|TestCase  $item
     * @return array<string, mixed>
     */
    protected function normalizeItemToArray(array|TestCase $item): array
    {
        if ($item instanceof TestCase) {
            $rawDsl = $item->getRawOriginal('dsl');
            $dsl = $item->dsl;
            if (is_string($rawDsl) && $rawDsl !== '') {
                try {
                    /** @var array<string, mixed>|\stdClass $decoded */
                    $decoded = json_decode($rawDsl, false, 512, JSON_THROW_ON_ERROR);
                    $dsl = $decoded;
                } catch (\JsonException) {
                    $dsl = $item->dsl;
                }
            }

            return [
                'id' => $item->id,
                'test_suite_id' => $item->test_suite_id,
                'endpoint_id' => $item->endpoint_id,
                'generation_job_id' => $item->generation_job_id,
                'source' => $item->source,
                'technique' => $item->technique,
                'title' => $item->title,
                'dsl' => $dsl,
                'dsl_version' => $item->dsl_version,
                'fingerprint' => $item->fingerprint,
                'status' => $item->status,
                'validation_errors' => $item->validation_errors,
                'repair_attempts' => $item->repair_attempts,
            ];
        }

        // If 'dsl' key is present, use it. Otherwise, assume item itself is the DSL.
        if (isset($item['dsl'])) {
            if (isset($item['status']) && is_string($item['status'])) {
                $enum = TestCaseStatus::tryFrom(strtoupper($item['status'])) ?? TestCaseStatus::tryFrom($item['status']);
                if ($enum !== null) {
                    $item['status'] = $enum;
                }
            }

            return $item;
        }

        $status = TestCaseStatus::Valid;
        if (isset($item['status'])) {
            if ($item['status'] instanceof TestCaseStatus) {
                $status = $item['status'];
            } elseif (is_string($item['status'])) {
                $status = TestCaseStatus::tryFrom(strtoupper($item['status'])) ?? TestCaseStatus::tryFrom($item['status']) ?? TestCaseStatus::Valid;
            }
        }

        return [
            'dsl' => $item,
            'title' => $item['title'] ?? 'Untitled test case',
            'dsl_version' => $item['dsl_version'] ?? '1.0',
            'status' => $status,
        ];
    }
}
