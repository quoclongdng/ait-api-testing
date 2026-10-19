<?php

namespace App\Models;

use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Models\Concerns\BelongsToLiveProject;
use App\Models\Concerns\HasIntegerRouteKey;
use Database\Factories\TestCaseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $test_suite_id
 * @property int $endpoint_id
 * @property int|null $generation_job_id
 * @property TestCaseSource $source
 * @property TestTechnique $technique
 * @property string $title
 * @property array<string, mixed> $dsl
 * @property string $dsl_version
 * @property string|null $fingerprint
 * @property TestCaseStatus $status
 * @property array<string, mixed>|null $validation_errors
 * @property int $repair_attempts
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read TestSuite $suite
 * @property-read Endpoint $endpoint
 * @property-read GenerationJob|null $generationJob
 * @property-read Collection<int, LlmCallLog> $llmCallLogs
 * @property-read Collection<int, TestResult> $test_results
 */
class TestCase extends Model
{
    /** @use HasFactory<TestCaseFactory> */
    use BelongsToLiveProject, HasFactory, HasIntegerRouteKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'test_suite_id',
        'endpoint_id',
        'generation_job_id',
        'source',
        'technique',
        'title',
        'dsl',
        'dsl_version',
        'fingerprint',
        'status',
        'validation_errors',
        'repair_attempts',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => TestCaseSource::class,
            'technique' => TestTechnique::class,
            'status' => TestCaseStatus::class,
            'dsl' => 'array',
            'validation_errors' => 'array',
            'repair_attempts' => 'integer',
        ];
    }

    /**
     * The stored DSL with JSON objects kept as objects. The `array` cast turns an empty object
     * (e.g. a json_schema `"properties": {}`) into `[]`, which is no longer a valid schema when the
     * DSL is shown and submitted again (M10, API).
     */
    public function dslObject(): object
    {
        $raw = $this->getRawOriginal('dsl');
        if (is_string($raw) && $raw !== '' && $this->dsl === json_decode($raw, true)) {
            $decoded = json_decode($raw, false);
            if (is_object($decoded)) {
                return $decoded;
            }
        }

        return (object) $this->dsl;
    }

    /**
     * @return BelongsTo<TestSuite, $this>
     */
    public function suite(): BelongsTo
    {
        return $this->belongsTo(TestSuite::class, 'test_suite_id');
    }

    /**
     * @return BelongsTo<TestSuite, $this>
     */
    public function test_suite(): BelongsTo
    {
        return $this->suite();
    }

    /**
     * @return BelongsTo<Endpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    /**
     * @return BelongsTo<GenerationJob, $this>
     */
    public function generationJob(): BelongsTo
    {
        return $this->belongsTo(GenerationJob::class);
    }

    /**
     * @return HasMany<LlmCallLog, $this>
     */
    public function llmCallLogs(): HasMany
    {
        return $this->hasMany(LlmCallLog::class);
    }

    /**
     * @return HasMany<TestResult, $this>
     */
    public function test_results(): HasMany
    {
        return $this->hasMany(TestResult::class);
    }

    protected function liveProjectRelation(): string
    {
        return 'suite.project';
    }
}
