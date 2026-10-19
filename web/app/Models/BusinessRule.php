<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLiveProject;
use App\Models\Concerns\HasIntegerRouteKey;
use Database\Factories\BusinessRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $endpoint_id
 * @property string $code
 * @property string $description
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Project $project
 * @property-read Endpoint|null $endpoint
 */
class BusinessRule extends Model
{
    /** @use HasFactory<BusinessRuleFactory> */
    use BelongsToLiveProject, HasFactory, HasIntegerRouteKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'endpoint_id',
        'code',
        'description',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Endpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    protected function liveProjectRelation(): string
    {
        return 'project';
    }
}
