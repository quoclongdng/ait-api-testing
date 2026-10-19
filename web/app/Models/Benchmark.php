<?php

namespace App\Models;

use App\Models\Concerns\HasIntegerRouteKey;
use Database\Factories\BenchmarkFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string $base_url
 * @property string $version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, BenchmarkBug> $bugs
 * @property-read Collection<int, Experiment> $experiments
 */
class Benchmark extends Model
{
    /** @use HasFactory<BenchmarkFactory> */
    use HasFactory, HasIntegerRouteKey;

    /**
     * Canonical "Benchmark #n" of the three benchmarks of the thesis, as numbered in docs/DESIGN.md §7
     * (BENCHMARK_PORT, BENCHMARK_ORDER_PORT, BENCHMARK_BOOKING_PORT) and the user guide (M14).
     *
     * @var array<string, int>
     */
    public const CANONICAL_NUMBERS = [
        'simple-store' => 1,
        'ecommerce-order' => 2,
        'appointment-booking' => 3,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'base_url',
        'version',
    ];

    /**
     * Canonical spelling of a benchmark version: trimmed, and a numeric version gets one lowercase
     * leading "v" ("1.0", "V1.0", " v1.0 " -> "v1.0"). ground-truth.yaml writes `version: "1.0"` while
     * M14 and the API accept free text, so both paths store this form and (slug, version) stays unique
     * (S14-06). A version that does not start with a digit after the optional "v" ("beta") is only
     * trimmed, instead of becoming "vbeta".
     */
    public static function normalizeVersion(string $version): string
    {
        $version = trim($version);

        return preg_match('/^[vV]?(\d.*)$/s', $version, $matches) === 1 ? 'v'.$matches[1] : $version;
    }

    /**
     * @return HasMany<BenchmarkBug, $this>
     */
    public function bugs(): HasMany
    {
        return $this->hasMany(BenchmarkBug::class);
    }

    /**
     * @return HasMany<Experiment, $this>
     */
    public function experiments(): HasMany
    {
        return $this->hasMany(Experiment::class);
    }
}
