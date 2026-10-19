<?php

namespace App\Models;

use App\Models\Concerns\InsertOnly;
use Database\Factories\ExperimentBugDetectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $experiment_id
 * @property int $benchmark_bug_id
 * @property int $repetition
 * @property bool $detected
 * @property int $clean_test_run_id
 * @property int $bug_test_run_id
 * @property array<string, mixed>|null $detecting_test_case_ids
 * @property Carbon $created_at
 * @property-read Experiment $experiment
 * @property-read BenchmarkBug $benchmarkBug
 * @property-read TestRun $cleanTestRun
 * @property-read TestRun $bugTestRun
 */
class ExperimentBugDetection extends Model
{
    /** @use HasFactory<ExperimentBugDetectionFactory> */
    use HasFactory;

    use InsertOnly;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'experiment_id',
        'benchmark_bug_id',
        'repetition',
        'detected',
        'clean_test_run_id',
        'bug_test_run_id',
        'detecting_test_case_ids',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'repetition' => 'integer',
            'detected' => 'boolean',
            'clean_test_run_id' => 'integer',
            'bug_test_run_id' => 'integer',
            'detecting_test_case_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Experiment, $this>
     */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    /**
     * @return BelongsTo<BenchmarkBug, $this>
     */
    public function benchmarkBug(): BelongsTo
    {
        return $this->belongsTo(BenchmarkBug::class);
    }

    /**
     * @return BelongsTo<TestRun, $this>
     */
    public function cleanTestRun(): BelongsTo
    {
        return $this->belongsTo(TestRun::class, 'clean_test_run_id');
    }

    /**
     * @return BelongsTo<TestRun, $this>
     */
    public function bugTestRun(): BelongsTo
    {
        return $this->belongsTo(TestRun::class, 'bug_test_run_id');
    }
}
