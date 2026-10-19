<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\HasIntegerRouteKey;
use App\Support\EmailAddress;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property UserRole $role
 * @property bool $is_active
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, AllowedTarget> $allowedTargets
 * @property-read Collection<int, TestSuite> $testSuites
 * @property-read Collection<int, GenerationJob> $generationJobs
 * @property-read Collection<int, TestRun> $testRuns
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasIntegerRouteKey, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Every write path (M03, /api/v1/admin/users, ait:admin:create, seeders) stores the canonical
     * lower-case email, matching the users_email_lower_unique index (QA S13-03).
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => (string) EmailAddress::normalize($value),
        );
    }

    /**
     * Check if the user is an Administrator.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Check if the user is a Tester.
     */
    public function isTester(): bool
    {
        return $this->role === UserRole::Tester;
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_id');
    }

    /**
     * @return HasMany<AllowedTarget, $this>
     */
    public function allowedTargets(): HasMany
    {
        return $this->hasMany(AllowedTarget::class, 'created_by');
    }

    /**
     * @return HasMany<TestSuite, $this>
     */
    public function testSuites(): HasMany
    {
        return $this->hasMany(TestSuite::class, 'created_by');
    }

    /**
     * @return HasMany<GenerationJob, $this>
     */
    public function generationJobs(): HasMany
    {
        return $this->hasMany(GenerationJob::class, 'created_by');
    }

    /**
     * @return HasMany<TestRun, $this>
     */
    public function testRuns(): HasMany
    {
        return $this->hasMany(TestRun::class, 'triggered_by');
    }
}
