<?php

namespace App\Models;

use App\Enums\TestVerdict;
use App\Models\Concerns\InsertOnly;
use Database\Factories\TestResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $test_run_id
 * @property int $test_case_id
 * @property TestVerdict $verdict
 * @property int $duration_ms
 * @property array<string, mixed> $request_log
 * @property array<string, mixed> $response_log
 * @property array<string, mixed> $assertion_results
 * @property string|null $error_message
 * @property Carbon $created_at
 * @property-read TestRun $testRun
 * @property-read TestCase $testCase
 */
class TestResult extends Model
{
    /** @use HasFactory<TestResultFactory> */
    use HasFactory;

    use InsertOnly;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'test_run_id',
        'test_case_id',
        'verdict',
        'duration_ms',
        'request_log',
        'response_log',
        'assertion_results',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verdict' => TestVerdict::class,
            'duration_ms' => 'integer',
            'request_log' => 'array',
            'response_log' => 'array',
            'assertion_results' => 'array',
        ];
    }

    /**
     * @return BelongsTo<TestRun, $this>
     */
    public function testRun(): BelongsTo
    {
        return $this->belongsTo(TestRun::class);
    }

    /**
     * @return BelongsTo<TestCase, $this>
     */
    public function testCase(): BelongsTo
    {
        return $this->belongsTo(TestCase::class);
    }
}
