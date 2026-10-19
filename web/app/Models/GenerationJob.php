<?php

namespace App\Models;

use App\Data\Experiments\AuthFixture;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Models\Concerns\BelongsToLiveProject;
use App\Models\Concerns\HasIntegerRouteKey;
use Database\Factories\GenerationJobFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $api_spec_id
 * @property int $test_suite_id
 * @property GenerationStrategy $strategy
 * @property GenerationJobStatus $status
 * @property array<string, mixed> $params
 * @property array<string, mixed>|null $stats
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Project $project
 * @property-read ApiSpec $apiSpec
 * @property-read TestSuite $testSuite
 * @property-read User $creator
 * @property-read Collection<int, TestCase> $testCases
 * @property-read Collection<int, LlmCallLog> $llmCallLogs
 */
class GenerationJob extends Model
{
    /** @use HasFactory<GenerationJobFactory> */
    use BelongsToLiveProject, HasFactory, HasIntegerRouteKey;

    /**
     * Timestamps are written with microseconds (QA S13-03): started_at/finished_at are
     * timestamptz(6), so run durations and the §11 generation time are not rounded to seconds.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'api_spec_id',
        'test_suite_id',
        'strategy',
        'status',
        'params',
        'stats',
        'error',
        'started_at',
        'finished_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategy' => GenerationStrategy::class,
            'status' => GenerationJobStatus::class,
            'params' => 'array',
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<ApiSpec, $this>
     */
    public function apiSpec(): BelongsTo
    {
        return $this->belongsTo(ApiSpec::class);
    }

    /**
     * @return BelongsTo<TestSuite, $this>
     */
    public function testSuite(): BelongsTo
    {
        return $this->belongsTo(TestSuite::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<TestCase, $this>
     */
    public function testCases(): HasMany
    {
        return $this->hasMany(TestCase::class);
    }

    /**
     * @return HasMany<LlmCallLog, $this>
     */
    public function llmCallLogs(): HasMany
    {
        return $this->hasMany(LlmCallLog::class);
    }

    /**
     * Get the effective target endpoint IDs for this generation job.
     * If params['endpoint_ids'] contains a non-empty array of IDs, filters to existing spec endpoints.
     * Otherwise (omitted or empty []), targets all endpoints of the associated API spec.
     *
     * @return list<int>
     */
    public function getTargetEndpointIds(): array
    {
        $this->loadMissing('apiSpec.endpoints');

        $params = $this->params;
        $selectedEndpointIds = null;
        if (isset($params['endpoint_ids']) && is_array($params['endpoint_ids'])) {
            $selectedEndpointIds = array_values(array_filter(
                array_map('intval', $params['endpoint_ids']),
                fn (int $id): bool => $id > 0
            ));
        }

        // The relation has no ORDER BY: PostgreSQL returns rows in heap order, which changes once a row is
        // updated. Spec (id) order keeps generation and the M11 per-endpoint table deterministic.
        /** @var Collection<int, Endpoint> $endpoints */
        $endpoints = $this->apiSpec->endpoints->sortBy('id');

        if ($selectedEndpointIds !== null && $selectedEndpointIds !== []) {
            /** @var list<int> */
            return $endpoints->whereIn('id', $selectedEndpointIds)->pluck('id')->values()->all();
        }

        /** @var list<int> */
        return $endpoints->pluck('id')->values()->all();
    }

    /** params key marking a job replayed from a recorded ai-service response (no LLM call, QA S14-06). */
    public const RECORDED_SOURCE_PARAM = 'recorded_source_job_id';

    /**
     * Whether the job replays a recorded LLM/HYBRID job of the official experiment (demo data): its
     * llm_call_logs are the recorded calls, while started_at/finished_at are those of the replay.
     */
    public function isReplayed(): bool
    {
        return is_int($this->params[self::RECORDED_SOURCE_PARAM] ?? null);
    }

    /**
     * Credential context of an experiment job (params.auth, ADR-0006): role names and per-operation
     * roles, never tokens. Null for ordinary M11 jobs.
     */
    public function authContext(): ?AuthFixture
    {
        return AuthFixture::tryFrom($this->params['auth'] ?? null);
    }

    /**
     * Run-time variables (`auth_token_<role>`) the experiment executor defines; generated cases may
     * reference them as `{{name}}` without extracting them first.
     *
     * @return list<string>
     */
    public function contextVariables(): array
    {
        return $this->authContext()?->variableNames() ?? [];
    }

    protected function liveProjectRelation(): string
    {
        return 'project';
    }
}
