<?php

namespace App\Providers;

use App\Contracts\AiGeneratorClient;
use App\Contracts\BenchmarkSandbox;
use App\Contracts\TargetHostResolver;
use App\Contracts\TargetHttpClient;
use App\Models\AllowedTarget;
use App\Models\ApiSpec;
use App\Models\Benchmark;
use App\Models\BusinessRule;
use App\Models\Experiment;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Policies\AllowedTargetPolicy;
use App\Policies\ApiSpecPolicy;
use App\Policies\BenchmarkPolicy;
use App\Policies\BusinessRulePolicy;
use App\Policies\ExperimentPolicy;
use App\Policies\GenerationJobPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TestCasePolicy;
use App\Policies\TestRunPolicy;
use App\Policies\TestSuitePolicy;
use App\Policies\UserPolicy;
use App\Services\Ai\FakeAiGeneratorClient;
use App\Services\Ai\HttpAiGeneratorClient;
use App\Services\Benchmark\BenchmarkTargetReset;
use App\Services\Benchmark\FakeBenchmarkSandbox;
use App\Services\Benchmark\GroundTruthLoader;
use App\Services\Benchmark\UrlTemplateBenchmarkSandbox;
use App\Services\Demo\DemoShowcaseFixture;
use App\Services\Execution\TargetAllowlistValidator;
use App\Services\Execution\TestExecutor;
use App\Services\Target\DnsTargetHostResolver;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\FakeTargetHttpClient;
use App\Services\Target\LaravelTargetHttpClient;
use App\Services\Target\TargetAllowlistService;
use App\Support\ProductionHardening;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Default max generation jobs a user may create per minute (security checklist §9: rate limit job
     * creation); RATE_LIMIT_GENERATION_PER_MINUTE overrides it (config ait.rate_limits).
     */
    public const GENERATION_JOBS_PER_MINUTE = 10;

    /**
     * Default max OpenAPI spec uploads a user may make per minute (§9): parsing a spec of up to 5 MB is
     * expensive, so the API and M06 upload routes share one counter per user (S13-03).
     * RATE_LIMIT_SPEC_UPLOADS_PER_MINUTE overrides it.
     */
    public const SPEC_UPLOADS_PER_MINUTE = 10;

    /** Warnings of {@see ProductionHardening}, logged once per process in boot(). */
    private static bool $hardeningReported = false;

    /** @var list<string> */
    private array $hardeningWarnings = [];

    /**
     * Bind every external-dependency contract to its real or fake implementation (D08).
     */
    public function register(): void
    {
        // Public deployment (S14-02): correct unsafe production config before anything reads it.
        $this->hardeningWarnings = ProductionHardening::apply($this->app->make(ConfigRepository::class), (string) $this->app->environment());

        $this->app->singleton(AiGeneratorClient::class, fn () => match (config('ait.ai_service.driver')) {
            'fake' => new FakeAiGeneratorClient,
            default => new HttpAiGeneratorClient(
                baseUrl: (string) config('ait.ai_service.url'),
                timeoutSeconds: (int) config('ait.ai_service.timeout_seconds'),
            ),
        });

        $this->app->singleton(TargetHttpClient::class, fn () => match (config('ait.target_client.driver')) {
            'fake' => new FakeTargetHttpClient,
            default => new LaravelTargetHttpClient,
        });

        $this->app->singleton(TargetHostResolver::class, fn () => match (config('ait.target_client.driver')) {
            'fake' => new FakeTargetHostResolver([
                'localhost' => ['127.0.0.1'],
                '127.0.0.1' => ['127.0.0.1'],
                'benchmark' => ['127.0.0.1'],
            ]),
            default => new DnsTargetHostResolver,
        });

        $this->app->singleton(TargetAllowlistValidator::class, fn () => new TargetAllowlistValidator(
            resolver: $this->app->make(TargetHostResolver::class),
        ));

        $this->app->singleton(TargetAllowlistService::class, fn () => new TargetAllowlistService(
            resolver: $this->app->make(TargetHostResolver::class),
        ));

        $this->app->singleton(BenchmarkSandbox::class, fn () => match (config('ait.benchmark.sandbox_driver')) {
            'fake' => new FakeBenchmarkSandbox,
            default => new UrlTemplateBenchmarkSandbox(
                template: (string) config('ait.benchmark.variant_url_template', ''),
                cleanTemplate: (string) config('ait.benchmark.clean_url_template', ''),
                client: $this->app->make(TargetHttpClient::class),
                allowlist: $this->app->make(TargetAllowlistValidator::class),
                resetTimeoutMs: (int) config('ait.benchmark.reset_timeout_ms', 10000),
            ),
        });

        $this->app->bind(BenchmarkTargetReset::class, fn () => new BenchmarkTargetReset(
            $this->app->make(BenchmarkSandbox::class),
            array_values(array_map('strval', (array) config('ait.benchmark.m12_reset_hosts', []))),
        ));

        $this->app->singleton(GroundTruthLoader::class, fn () => new GroundTruthLoader(
            (string) config('ait.benchmark.path', base_path('../benchmark')),
        ));

        $this->app->bind(DemoShowcaseFixture::class, fn () => new DemoShowcaseFixture(
            (string) config('ait.demo_showcase.fixture_path', database_path('demo/benchmark-v1.0.json.gz')),
        ));

        $this->app->singleton(TestExecutor::class, fn () => new TestExecutor(
            targetClient: $this->app->make(TargetHttpClient::class),
            allowlistValidator: $this->app->make(TargetAllowlistValidator::class),
        ));
    }

    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(AllowedTarget::class, AllowedTargetPolicy::class);
        Gate::policy(ApiSpec::class, ApiSpecPolicy::class);
        Gate::policy(TestSuite::class, TestSuitePolicy::class);
        Gate::policy(TestCase::class, TestCasePolicy::class);
        Gate::policy(GenerationJob::class, GenerationJobPolicy::class);
        Gate::policy(BusinessRule::class, BusinessRulePolicy::class);
        Gate::policy(TestRun::class, TestRunPolicy::class);
        Gate::policy(Benchmark::class, BenchmarkPolicy::class);
        Gate::policy(Experiment::class, ExperimentPolicy::class);
        Gate::define('admin', fn (User $user): bool => $user->isAdmin());
        Gate::define('tester', fn (User $user): bool => $user->isTester() || $user->isAdmin());

        // @invalid('field', 'error-id'[, $when]): aria-invalid/aria-describedby for a control whose
        // server-side validation error is rendered by <x-field-error> (S13-03 accessibility).
        // Registered only once the Blade compiler is resolved: resolving it here would require the
        // compiled-view directory, which does not exist while `composer dump-autoload` runs
        // package:discover in the vendor stage of docker/web/Dockerfile.prod ("Please provide a valid
        // cache path.").
        $this->callAfterResolving('blade.compiler', static function (BladeCompiler $blade): void {
            $blade->directive('invalid', fn (string $expression): string => '<?php echo \\App\\Support\\FieldA11y::attributes($errors ?? null, '.$expression.'); ?>');
        });

        $this->reportProductionHardening();
        // Behind Cloudflare/a reverse proxy the app may see plain HTTP; links and redirects must still
        // use the public https:// origin of APP_URL.
        if (str_starts_with(strtolower((string) config('app.url')), 'https://')) {
            URL::forceScheme('https');
        }

        // docs/DESIGN.md §9: rate limit endpoints that start long-running work. Each limiter has a
        // per-minute burst limit and an hourly budget per user (config ait.rate_limits, §7).
        RateLimiter::for('test-runs', fn (Request $request): array => $this->limits(
            $request, 'test-runs', 'test_runs',
        ));
        RateLimiter::for('generation', fn (Request $request): array => $this->limits(
            $request, 'generation', 'generation',
            'Bạn đã tạo quá nhiều tiến trình sinh test. Vui lòng thử lại sau ít phút.',
        ));
        RateLimiter::for('experiments', fn (Request $request): array => $this->limits(
            $request, 'experiments', 'experiments',
        ));
        // Keep only the small form fields on redirect: the pasted spec may be up to 5 MB.
        RateLimiter::for('spec-uploads', fn (Request $request): array => $this->limits(
            $request, 'spec-uploads', 'spec_uploads',
            'Bạn đã tải lên quá nhiều đặc tả OpenAPI. Vui lòng thử lại sau ít phút.',
            ['version_label', 'input_method'],
        ));
        // The web and /api/v1 generation-job routes both use the single 'generation'
        // limiter above, so one user shares one counter across both entry points.
    }

    /**
     * Per-user burst (per minute) and hourly limits of one expensive action. Each limit has its own
     * key: two limits with the same key would share one counter. API/JSON callers get the §6
     * RATE_LIMITED envelope; web forms with a $webMessage go back with that message.
     *
     * @param  list<string>|null  $keepInput  fields flashed back to the form (null = all)
     * @return list<Limit>
     */
    private function limits(Request $request, string $name, string $configKey, ?string $webMessage = null, ?array $keepInput = null): array
    {
        $user = $request->user()?->getAuthIdentifier() ?? $request->ip();
        $key = $name.':'.(is_scalar($user) ? (string) $user : '');
        $perMinute = max(1, (int) config("ait.rate_limits.{$configKey}_per_minute", 10));
        $perHour = max($perMinute, (int) config("ait.rate_limits.{$configKey}_per_hour", 60));

        $limits = [
            Limit::perMinute($perMinute)->by($key),
            Limit::perHour($perHour)->by($key.':hour'),
        ];
        if ($webMessage === null) {
            return $limits;
        }

        $respond = function (Request $request, array $headers) use ($webMessage, $keepInput): RedirectResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                throw new ThrottleRequestsException('Too many requests.', null, $headers);
            }
            $back = back();
            $back = $keepInput === null ? $back->withInput() : $back->withInput($request->only($keepInput));

            return $back->with('error', $webMessage)->withHeaders($headers);
        };

        return array_map(static fn (Limit $limit): Limit => $limit->response($respond), $limits);
    }

    /** Log corrected unsafe production settings loudly, once per PHP process. */
    private function reportProductionHardening(): void
    {
        if ($this->hardeningWarnings === [] || self::$hardeningReported) {
            return;
        }
        self::$hardeningReported = true;
        foreach ($this->hardeningWarnings as $warning) {
            Log::critical('[S14-02 production hardening] '.$warning);
        }
    }
}
