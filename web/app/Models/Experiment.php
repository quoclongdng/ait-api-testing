<?php

namespace App\Models;

use App\Enums\ExperimentStatus;
use App\Enums\GenerationStrategy;
use App\Models\Concerns\HasIntegerRouteKey;
use Database\Factories\ExperimentFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int $benchmark_id
 * @property GenerationStrategy $strategy
 * @property array<string, mixed> $config
 * @property ExperimentStatus $status
 * @property array<string, mixed>|null $metrics
 * @property array<int, array<string, mixed>>|null $repetitions_data
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Benchmark $benchmark
 * @property-read Collection<int, ExperimentBugDetection> $bugDetections
 */
class Experiment extends Model
{
    /** @use HasFactory<ExperimentFactory> */
    use HasFactory, HasIntegerRouteKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'benchmark_id',
        'strategy',
        'config',
        'status',
        'metrics',
        'repetitions_data',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategy' => GenerationStrategy::class,
            'status' => ExperimentStatus::class,
            'config' => 'array',
            'metrics' => 'array',
            'repetitions_data' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Benchmark, $this>
     */
    public function benchmark(): BelongsTo
    {
        return $this->belongsTo(Benchmark::class);
    }

    /**
     * @return HasMany<ExperimentBugDetection, $this>
     */
    public function bugDetections(): HasMany
    {
        return $this->hasMany(ExperimentBugDetection::class);
    }
}
