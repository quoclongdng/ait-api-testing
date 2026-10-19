<?php

namespace App\Models;

use App\Enums\TestRunStatus;
use App\Models\Concerns\BelongsToLiveProject;
use App\Models\Concerns\HasIntegerRouteKey;
use Database\Factories\TestRunFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $test_suite_id
 * @property string $target_base_url
 * @property string|null $benchmark_variant
 * @property TestRunStatus $status
 * @property array<string, mixed>|null $totals
 * @property int $triggered_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read TestSuite $testSuite
 * @property-read User $triggerer
 * @property-read Collection<int, TestResult> $testResults
 */
class TestRun extends Model
{
    /** @use HasFactory<TestRunFactory> */
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
        'test_suite_id',
        'target_base_url',
        'benchmark_variant',
        'status',
        'totals',
        'triggered_by',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TestRunStatus::class,
            'totals' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
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
    public function triggerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * @return HasMany<TestResult, $this>
     */
    public function testResults(): HasMany
    {
        return $this->hasMany(TestResult::class);
    }

    protected function liveProjectRelation(): string
    {
        return 'testSuite.project';
    }
}
