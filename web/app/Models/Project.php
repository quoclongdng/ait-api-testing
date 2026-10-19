<?php

namespace App\Models;

use App\Models\Concerns\HasIntegerRouteKey;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $owner_id
 * @property string|null $default_target_url
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $owner
 * @property-read Collection<int, ApiSpec> $apiSpecs
 * @property-read Collection<int, ApiSpec> $specs
 * @property-read Collection<int, BusinessRule> $businessRules
 * @property-read Collection<int, TestSuite> $testSuites
 * @property-read Collection<int, GenerationJob> $generationJobs
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasIntegerRouteKey, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'owner_id',
        'default_target_url',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<ApiSpec, $this>
     */
    public function apiSpecs(): HasMany
    {
        return $this->hasMany(ApiSpec::class);
    }

    /**
     * @return HasMany<ApiSpec, $this>
     */
    public function specs(): HasMany
    {
        return $this->apiSpecs();
    }

    /**
     * @return HasMany<BusinessRule, $this>
     */
    public function businessRules(): HasMany
    {
        return $this->hasMany(BusinessRule::class);
    }

    /**
     * @return HasMany<TestSuite, $this>
     */
    public function testSuites(): HasMany
    {
        return $this->hasMany(TestSuite::class);
    }

    /**
     * @return HasMany<GenerationJob, $this>
     */
    public function generationJobs(): HasMany
    {
        return $this->hasMany(GenerationJob::class);
    }
}
