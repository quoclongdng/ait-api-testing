<?php

namespace App\Models;

use App\Enums\LlmPurpose;
use App\Models\Concerns\InsertOnly;
use Database\Factories\LlmCallLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $generation_job_id
 * @property int|null $test_case_id
 * @property string $provider
 * @property string $model
 * @property LlmPurpose $purpose
 * @property array<string, mixed> $prompt
 * @property string $response_raw
 * @property float $temperature
 * @property int|null $seed
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int $latency_ms
 * @property string|null $error
 * @property Carbon $created_at
 * @property-read GenerationJob $generationJob
 * @property-read TestCase|null $testCase
 */
class LlmCallLog extends Model
{
    /** @use HasFactory<LlmCallLogFactory> */
    use HasFactory;

    use InsertOnly;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'generation_job_id',
        'test_case_id',
        'provider',
        'model',
        'purpose',
        'prompt',
        'response_raw',
        'temperature',
        'seed',
        'prompt_tokens',
        'completion_tokens',
        'latency_ms',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => LlmPurpose::class,
            'prompt' => 'array',
            'temperature' => 'float',
            'seed' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<GenerationJob, $this>
     */
    public function generationJob(): BelongsTo
    {
        return $this->belongsTo(GenerationJob::class);
    }

    /**
     * @return BelongsTo<TestCase, $this>
     */
    public function testCase(): BelongsTo
    {
        return $this->belongsTo(TestCase::class);
    }
}
