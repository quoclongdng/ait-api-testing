<?php

namespace App\Services\Benchmark;

use App\Contracts\BenchmarkSandbox;
use App\Contracts\TargetHttpClient;
use App\Data\TargetRequest;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Services\Execution\TargetAllowlistValidator;
use Illuminate\Support\Facades\Log;

/**
 * Resolves bug variants through BENCHMARK_VARIANT_URL_TEMPLATE, e.g.
 * `http://{slug}-{toggle}:8000` → `http://simple-store-bug_001:8000`.
 *
 * Placeholders: {slug}, {version}, {code} (BUG-001), {code_lower} (bug-001), {toggle} (bug_001),
 * {base_url}. Each variant must be a separately started sandbox with only that toggle enabled
 * and must be on the target allowlist (D07).
 *
 * The clean variant uses BENCHMARK_CLEAN_URL_TEMPLATE (placeholders {slug}, {version},
 * {base_url}; default `http://{slug}:8000`, the Docker service name of the benchmark) so the
 * worker reaches it inside Docker; an empty template falls back to the benchmark's base_url.
 *
 * Sandboxes are reset through `POST <variant>/__admin/reset` sent with the TargetHttpClient
 * contract after the D07 allowlist check (host resolved first, no redirects).
 */
final class UrlTemplateBenchmarkSandbox implements BenchmarkSandbox
{
    public const RESET_PATH = '/__admin/reset';

    public function __construct(
        private readonly string $template,
        private readonly string $cleanTemplate = '',
        private readonly ?TargetHttpClient $client = null,
        private readonly ?TargetAllowlistValidator $allowlist = null,
        private readonly int $resetTimeoutMs = 10000,
    ) {}

    public function ensureAvailable(Benchmark $benchmark): void
    {
        if (trim($this->template) === '') {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Chưa cấu hình BENCHMARK_VARIANT_URL_TEMPLATE nên không thể chạy các biến thể có bug của benchmark.',
                ['benchmark_id' => ["Benchmark #{$benchmark->id} chưa có sandbox cho biến thể bug."]]
            );
        }
    }

    public function variantBaseUrl(Benchmark $benchmark, ?BenchmarkBug $bug): string
    {
        $baseUrl = rtrim($benchmark->base_url, '/');

        if ($bug === null) {
            if (trim($this->cleanTemplate) === '') {
                return $baseUrl;
            }

            return rtrim(strtr(trim($this->cleanTemplate), [
                '{slug}' => $benchmark->slug,
                '{version}' => $benchmark->version,
                '{base_url}' => $baseUrl,
            ]), '/');
        }

        $this->ensureAvailable($benchmark);

        return rtrim(strtr(trim($this->template), [
            '{slug}' => $benchmark->slug,
            '{version}' => $benchmark->version,
            '{code}' => $bug->code,
            '{code_lower}' => strtolower($bug->code),
            '{toggle}' => strtolower($bug->toggle_flag),
            '{base_url}' => $baseUrl,
        ]), '/');
    }

    public function reset(string $baseUrl): void
    {
        $url = rtrim($baseUrl, '/').self::RESET_PATH;
        ($this->allowlist ?? new TargetAllowlistValidator)->assertAllowed($url);

        if ($this->client === null) {
            throw new ApiException(ErrorCode::InternalError, 'Sandbox benchmark chưa được cấu hình HTTP client để reset.');
        }

        $response = $this->client->send(new TargetRequest(
            method: 'POST',
            url: $url,
            headers: ['Accept' => 'application/json'],
            timeoutMs: max(1, $this->resetTimeoutMs),
        ));

        if ($response->status === 404) {
            Log::warning('Benchmark sandbox has no reset endpoint; running on its current data', ['url' => $url]);

            return;
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw new ApiException(ErrorCode::InternalError, "Reset sandbox {$url} thất bại (HTTP {$response->status}).");
        }
    }
}
