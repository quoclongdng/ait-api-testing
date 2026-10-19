<?php

namespace App\Models;

use App\Casts\OpenApiSpecCast;
use App\Enums\ApiSpecFormat;
use App\Enums\ApiSpecStatus;
use App\Models\Concerns\BelongsToLiveProject;
use App\Models\Concerns\HasIntegerRouteKey;
use Database\Factories\ApiSpecFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $version_label
 * @property string $source_filename
 * @property ApiSpecFormat $format
 * @property string $openapi_version
 * @property string $raw_content
 * @property array<string, mixed>|null $normalized
 * @property string $checksum
 * @property ApiSpecStatus $status
 * @property array<string, mixed>|null $parse_errors
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Project $project
 * @property-read Collection<int, Endpoint> $endpoints
 * @property-read Collection<int, TestSuite> $testSuites
 */
class ApiSpec extends Model
{
    /** @use HasFactory<ApiSpecFactory> */
    use BelongsToLiveProject, HasFactory, HasIntegerRouteKey;

    /**
     * openapi_version of a PARSE_FAILED spec that declares no OpenAPI 3.x version. The column is
     * NOT NULL and web-api.openapi.yaml requires a non-empty string, so a marker is stored instead
     * of a made-up "3.0.0" or ''; screens render it as "—" (S13-03).
     */
    public const UNKNOWN_OPENAPI_VERSION = 'unknown';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'version_label',
        'source_filename',
        'format',
        'openapi_version',
        'raw_content',
        'normalized',
        'checksum',
        'status',
        'parse_errors',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => ApiSpecFormat::class,
            'status' => ApiSpecStatus::class,
            'normalized' => OpenApiSpecCast::class,
            'parse_errors' => 'array',
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
     * @return HasMany<Endpoint, $this>
     */
    public function endpoints(): HasMany
    {
        return $this->hasMany(Endpoint::class);
    }

    /**
     * @return HasMany<TestSuite, $this>
     */
    public function testSuites(): HasMany
    {
        return $this->hasMany(TestSuite::class);
    }

    /** OpenAPI version for the UI: "—" when the document declared none. */
    public function displayOpenApiVersion(): string
    {
        $version = $this->openapi_version;

        return ($version === '' || $version === self::UNKNOWN_OPENAPI_VERSION) ? '—' : $version;
    }

    protected function liveProjectRelation(): string
    {
        return 'project';
    }
}
