<?php

namespace App\Models;

use App\Enums\BenchmarkBugCategory;
use App\Enums\HttpMethod;
use Database\Factories\BenchmarkBugFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $benchmark_id
 * @property string $code
 * @property HttpMethod $method
 * @property string $path
 * @property BenchmarkBugCategory $category
 * @property string $description
 * @property string $toggle_flag
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Benchmark $benchmark
 * @property-read Collection<int, ExperimentBugDetection> $bugDetections
 */
class BenchmarkBug extends Model
{
    /** @use HasFactory<BenchmarkBugFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'benchmark_id',
        'code',
        'method',
        'path',
        'category',
        'description',
        'toggle_flag',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => HttpMethod::class,
            'category' => BenchmarkBugCategory::class,
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
