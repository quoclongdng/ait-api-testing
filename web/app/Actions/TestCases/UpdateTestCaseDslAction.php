<?php

namespace App\Actions\TestCases;

use App\Enums\ErrorCode;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Exceptions\ApiException;
use App\Models\Endpoint;
use App\Models\TestCase;
use App\Models\TestSuite;
use App\Services\Dsl\DslValidator;
use App\Services\TestEngine\TestDeduplicationService;
use App\Services\TestEngine\TestFingerprintService;
use Illuminate\Support\Facades\DB;
use stdClass;

class UpdateTestCaseDslAction
{
    public function __construct(
        private readonly DslValidator $dslValidator,
        private readonly TestFingerprintService $fingerprintService,
        private readonly TestDeduplicationService $deduplicationService,
    ) {}

    /**
     * Manual DSL edit, restricted to the transitions allowed by §4.2:
     * - INVALID -> VALID when the edited DSL validates; an edit that still fails keeps the case INVALID.
     * - VALID -> VALID and APPROVED -> VALID (needs re-approval) only when the edited DSL is valid and unique.
     * Edits that would move a VALID/APPROVED case to INVALID (DSL_INVALID) or any case to DUPLICATE (CONFLICT)
     * are rejected without touching the row. ARCHIVED and DUPLICATE cases are read-only.
     *
     * Pass the DSL as stdClass (decoded with objects) to keep `{}` distinct from `[]`; an associative
     * array is accepted for callers that never had empty objects.
     *
     * @param  array<string, mixed>|stdClass  $input
     *
     * @throws ApiException
     */
    public function execute(TestCase $testCase, array|stdClass $input, ?string $title = null): TestCase
    {
        $encoded = json_encode($input);
        $dslObject = $input instanceof stdClass ? $input : json_decode((string) $encoded, false);
        $dslObject = $dslObject instanceof stdClass ? $dslObject : new stdClass;
        /** @var array<string, mixed> $dsl */
        $dsl = is_string($encoded) ? (array) json_decode($encoded, true) : [];

        // Same row lock as TransitionTestCaseStatusAction, plus the suite lock used for dedup, so a
        // concurrent approve/archive or edit cannot be overwritten (e.g. ARCHIVED -> VALID, §4.2) and
        // two edits cannot both pass the fingerprint check (§10.5).
        return DB::transaction(function () use ($testCase, $dslObject, $dsl, $title): TestCase {
            TestSuite::query()->lockForUpdate()->whereKey($testCase->test_suite_id)->first();
            $locked = TestCase::query()->lockForUpdate()->whereKey($testCase->getKey())->firstOrFail();

            return $this->update($locked, $dslObject, $dsl, $title);
        });
    }

    /**
     * @param  array<string, mixed>  $dsl  associative view of $dslObject (for reading fields)
     *
     * @throws ApiException
     */
    private function update(TestCase $testCase, stdClass $dslObject, array $dsl, ?string $title): TestCase
    {
        $editable = [TestCaseStatus::Invalid, TestCaseStatus::Valid, TestCaseStatus::Approved];
        if (! in_array($testCase->status, $editable, true)) {
            throw new ApiException(
                ErrorCode::InvalidStateTransition,
                "Không thể sửa DSL của test case ở trạng thái {$testCase->status->value}."
            );
        }

        $finalTitle = $this->syncTitle($testCase, $dslObject, $dsl, $title);

        $apiSpec = $testCase->suite->apiSpec;

        // Schema + semantic validation against the suite's spec (D04)
        // A case of an experiment job may use the run's credential variables (ADR-0006).
        $validationResult = $this->dslValidator->validate($dslObject, $apiSpec, $testCase->generationJob?->contextVariables() ?? []);

        $fingerprint = null;
        $fingerprintError = null;
        try {
            $fingerprint = $this->fingerprintService->calculate($dslObject);
        } catch (\InvalidArgumentException $e) {
            $fingerprintError = $e->getMessage();
        }

        if (! $validationResult->isValid || $fingerprint === null) {
            $validationErrors = $validationResult->errors;
            if ($fingerprintError !== null) {
                $validationErrors[] = $fingerprintError;
            }

            if ($testCase->status !== TestCaseStatus::Invalid) {
                // VALID/APPROVED -> INVALID is not a §4.2 transition: keep the previous DSL and status.
                throw new ApiException(
                    ErrorCode::DslInvalid,
                    'DSL không hợp lệ, thay đổi chưa được lưu.',
                    ['dsl' => $validationErrors]
                );
            }

            $newStatus = TestCaseStatus::Invalid;
        } else {
            // Only active cases (VALID/APPROVED) can block an edit: DUPLICATE rows share their original's
            // fingerprint and ARCHIVED cases are retired, so neither may reject a title-only edit of the original.
            $duplicateOf = $this->deduplicationService->findByFingerprint(
                $fingerprint,
                $testCase->test_suite_id,
                $testCase->id,
                [TestCaseStatus::Valid, TestCaseStatus::Approved],
            );
            if ($duplicateOf !== null) {
                // No §4.2 transition leads to DUPLICATE from an edit: reject without updating the row.
                throw new ApiException(
                    ErrorCode::Conflict,
                    "Nội dung DSL trùng với test case #{$duplicateOf->id} trong bộ kiểm thử, thay đổi chưa được lưu.",
                    ['duplicate_of' => $duplicateOf->id]
                );
            }

            $newStatus = TestCaseStatus::Valid;
            $validationErrors = null;
        }

        $attributes = [
            'title' => $finalTitle,
            'dsl' => $dslObject,
            'fingerprint' => $fingerprint,
            'source' => TestCaseSource::Manual,
            'status' => $newStatus,
            'validation_errors' => $validationErrors,
        ];

        if ($newStatus !== TestCaseStatus::Invalid) {
            // Only a validated DSL may change dsl_version (an INVALID draft such as "9.9" keeps the stored version).
            if (is_string($dsl['dsl_version'] ?? null)) {
                $attributes['dsl_version'] = $dsl['dsl_version'];
            }

            $technique = TestTechnique::tryFrom(is_string($dsl['technique'] ?? null) ? $dsl['technique'] : '');
            if ($technique !== null) {
                $attributes['technique'] = $technique;
            }

            $endpoint = $this->resolveEndpoint($apiSpec->id, $dsl);
            if ($endpoint !== null) {
                $attributes['endpoint_id'] = $endpoint->id;
            }
        }

        $testCase->update($attributes);

        return $testCase->refresh();
    }

    /**
     * One title for the case (S14-06 live QA r7): the title column and dsl.title never disagree, so
     * lists, reports and exports show the title of the DSL that actually runs. The separate title
     * input wins only when the user changed it (or the DSL has no title) and is then written into
     * dsl.title; otherwise the column follows dsl.title. The title is not part of the fingerprint
     * (§10.5), so syncing it never changes dedup.
     *
     * @param  array<string, mixed>  $dsl  updated in place with $dslObject
     */
    private function syncTitle(TestCase $testCase, stdClass $dslObject, array &$dsl, ?string $title): string
    {
        $dslTitle = is_string($dsl['title'] ?? null) ? $dsl['title'] : null;
        $inputWins = $title !== null && $title !== '' && ($title !== $testCase->title || ! property_exists($dslObject, 'title'));

        if ($inputWins) {
            $final = mb_substr($title, 0, 255);
            $dslObject->title = $final;
            $dsl['title'] = $final;

            return $final;
        }

        return mb_substr($dslTitle ?? $testCase->title, 0, 255);
    }

    /**
     * @param  array<string, mixed>  $dsl
     */
    private function resolveEndpoint(int $apiSpecId, array $dsl): ?Endpoint
    {
        $endpoint = $dsl['endpoint'] ?? null;
        if (! is_array($endpoint) || ! is_string($endpoint['method'] ?? null) || ! is_string($endpoint['path'] ?? null)) {
            return null;
        }

        return Endpoint::query()
            ->where('api_spec_id', $apiSpecId)
            ->where('method', strtoupper($endpoint['method']))
            ->where('path', $endpoint['path'])
            ->first();
    }
}
