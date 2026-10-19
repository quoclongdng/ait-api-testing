<?php

namespace App\Models;

use App\Casts\OpenApiSpecCast;
use App\Enums\HttpMethod;
use App\Support\JsonPreserver;
use Database\Factories\EndpointFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $api_spec_id
 * @property HttpMethod $method
 * @property string $path
 * @property string|null $operation_id
 * @property string|null $summary
 * @property string|null $description
 * @property array<string, mixed>|null $parameters
 * @property array<string, mixed>|null $request_body
 * @property array<string, mixed> $responses
 * @property array<string, mixed>|null $security
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ApiSpec $apiSpec
 * @property-read Collection<int, BusinessRule> $businessRules
 * @property-read Collection<int, TestCase> $testCases
 */
class Endpoint extends Model
{
    /** @use HasFactory<EndpointFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'api_spec_id',
        'method',
        'path',
        'operation_id',
        'summary',
        'description',
        'parameters',
        'request_body',
        'responses',
        'security',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => HttpMethod::class,
            'parameters' => OpenApiSpecCast::class,
            'request_body' => OpenApiSpecCast::class,
            'responses' => OpenApiSpecCast::class,
            'security' => OpenApiSpecCast::class,
        ];
    }

    public function getPreservedRequestBody(): ?object
    {
        return JsonPreserver::preserveRequestBody($this);
    }

    public function getPreservedResponses(): object
    {
        return JsonPreserver::preserveResponses($this);
    }

    /**
     * @return list<mixed>|null
     */
    public function getPreservedParameters(): ?array
    {
        return JsonPreserver::preserveParameters($this);
    }

    /**
     * @return BelongsTo<ApiSpec, $this>
     */
    public function apiSpec(): BelongsTo
    {
        return $this->belongsTo(ApiSpec::class);
    }

    /**
     * @return HasMany<BusinessRule, $this>
     */
    public function businessRules(): HasMany
    {
        return $this->hasMany(BusinessRule::class);
    }

    /**
     * @return HasMany<TestCase, $this>
     */
    public function testCases(): HasMany
    {
        return $this->hasMany(TestCase::class);
    }
}
