<?php

namespace App\Models;

use App\Models\Concerns\HasIntegerRouteKey;
use Database\Factories\AllowedTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $host
 * @property int|null $port
 * @property string|null $note
 * @property int $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $creator
 */
class AllowedTarget extends Model
{
    /** @use HasFactory<AllowedTargetFactory> */
    use HasFactory, HasIntegerRouteKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'host',
        'port',
        'note',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
