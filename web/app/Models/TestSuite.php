<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLiveProject;
use App\Models\Concerns\HasIntegerRouteKey;
use Database\Factories\TestSuiteFactory;
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
 * @property string $name
 * @property string|null $description
 * @property int $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Project $project
 * @property-read ApiSpec $apiSpec
 * @property-read User $creator
 * @property-read Collection<int, TestCase> $testCases
 * @property-read Collection<int, GenerationJob> $generationJobs
 * @property-read Collection<int, TestRun> $testRuns
 */
class TestSuite extends Model
{
    /** @use HasFactory<TestSuiteFactory> */
    use BelongsToLiveProject, HasFactory, HasIntegerRouteKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'api_spec_id',
        'name',
        'description',
        'created_by',
    ];

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
     * @return HasMany<GenerationJob, $this>
     */
    public function generationJobs(): HasMany
    {
        return $this->hasMany(GenerationJob::class);
    }

    /**
     * @return HasMany<TestRun, $this>
     */
    public function testRuns(): HasMany
    {
        return $this->hasMany(TestRun::class);
    }

    protected function liveProjectRelation(): string
    {
        return 'project';
    }
}
