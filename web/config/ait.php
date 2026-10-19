<?php

/*
 * AI Testing Platform settings. Every key here must be documented in docs/DESIGN.md §7.
 */

// Public deployment (S14-02): production defaults are stricter than local development.
$production = env('APP_ENV', 'production') === 'production';

return [

    // Time zone used to DISPLAY timestamps in the UI and exports (stored values stay in app.timezone = UTC).
    'display_timezone' => (string) env('APP_DISPLAY_TIMEZONE', 'Asia/Ho_Chi_Minh'),

    'ai_service' => [
        'driver' => env('AI_SERVICE_DRIVER', 'http'),
        'url' => env('AI_SERVICE_URL', 'http://ai-service:8001'),
        // One POST /v1/generate = ai-service AI_GENERATE_BUDGET_SECONDS (1320) + 60 s margin;
        // must stay below GenerateTestsJob / RunExperimentStepJob timeouts (1500 s).
        'timeout_seconds' => (int) env('AI_SERVICE_TIMEOUT', 1380),
        // Giá trị tham khảo hiển thị tại M04; hiện chưa được áp dụng vào request sinh test.
        // AI Service sử dụng cấu hình runtime riêng khi options.model = null.
        'default_model' => (string) env('AI_LLM_MODEL', 'qwen2.5-coder:7b'),
        'default_temperature' => (float) env('AI_LLM_TEMPERATURE', 0.2),
        'default_seed' => (int) env('AI_LLM_SEED', 42),
    ],

    'target_client' => [
        // http | fake
        'driver' => env('TARGET_CLIENT_DRIVER', 'http'),
        'default_timeout_ms' => (int) env('TARGET_DEFAULT_TIMEOUT_MS', 10000),
        // Refuse loopback/private addresses for targets added through allowed_targets (M04); the
        // internal benchmark services stay reachable through ALLOWED_TARGET_HOSTS. Link-local and
        // cloud-metadata addresses are refused for every target regardless of this flag (S14-02).
        'block_private_networks' => filter_var(env('TARGET_BLOCK_PRIVATE_NETWORKS', $production), FILTER_VALIDATE_BOOL),
    ],

    // Comma-separated host[:port] list the Test Executor may call (D07). In production (ADR-0007)
    // loopback entries (localhost, 127.0.0.1, ::1) are dropped at boot by
    // App\Support\ProductionHardening: there they would mean the web container itself.
    'allowed_target_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ALLOWED_TARGET_HOSTS', 'localhost,127.0.0.1,benchmark,benchmark-order,benchmark-booking,simple-store,ecommerce-order,appointment-booking')),
    ))),

    // Benchmark sandboxes and experiments (S9-02, D13, D14).
    'benchmark' => [
        // Directory holding benchmark/<slug>/{ground-truth.yaml,openapi.yaml}.
        'path' => (string) env('BENCHMARK_PATH', base_path('../benchmark')),
        // url_template | fake
        'sandbox_driver' => env('BENCHMARK_SANDBOX_DRIVER', 'url_template'),
        // Base URL of a single-bug variant, e.g. http://{slug}-{code_lower}:8000 (empty = not available).
        'variant_url_template' => (string) env('BENCHMARK_VARIANT_URL_TEMPLATE', ''),
        // Base URL of the clean variant reachable from the worker, e.g. http://{slug}:8000
        // (placeholders {slug} {version} {base_url}; empty = the benchmark's stored base_url).
        'clean_url_template' => (string) env('BENCHMARK_CLEAN_URL_TEMPLATE', 'http://{slug}:8000'),
        // Timeout of POST <variant>/__admin/reset before each clean and bug-variant run (S11-02).
        'reset_timeout_ms' => (int) env('BENCHMARK_RESET_TIMEOUT_MS', 10000),
        // ADR-0006 addendum 2026-10-13 (QA S13-03): an ordinary M12 run whose target host is one of
        // these benchmark sandboxes resets it (POST <origin>/__admin/reset) before every test case,
        // so re-running a suite on the shared clean benchmark gives the same verdicts. Other targets
        // are never reset. Empty = M12 never resets.
        'm12_reset_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('RUN_RESET_BENCHMARK_HOSTS', 'benchmark,benchmark-order,benchmark-booking,simple-store,ecommerce-order,appointment-booking')),
        ))),
    ],

    // Experiment protocol (experiment-design.md §4.2): repetition r uses seeds[r-1].
    'experiments' => [
        'seeds' => array_values(array_map('intval', array_filter(array_map(
            'trim',
            explode(',', (string) env('EXPERIMENT_SEEDS', '42,1024,2026')),
        ), static fn (string $seed): bool => preg_match('/^[0-9]+$/', $seed) === 1))),
        // ADR-0006 addendum 2026-10-12: POST <variant>/__admin/reset before every test case of an
        // experiment run (clean and bug variants, every strategy), snapshotted into
        // experiments.config.reset_per_case. Ordinary M12 runs follow benchmark.m12_reset_hosts.
        'reset_per_case' => filter_var(env('EXPERIMENT_RESET_PER_CASE', true), FILTER_VALIDATE_BOOL),
        // false = this deployment has no single-bug sandboxes (production profile, ADR-0007): M15 and
        // POST /api/v1/experiments refuse to create experiments; existing ones stay readable.
        'enabled' => filter_var(env('EXPERIMENTS_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

    // Public deployment hardening (S14-02, ADR-0007).
    'security' => [
        // Security headers (CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy).
        'headers_enabled' => filter_var(env('SECURITY_HEADERS_ENABLED', true), FILTER_VALIDATE_BOOL),
        // Strict-Transport-Security max-age, sent only on HTTPS requests (0 = never).
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
    ],

    // Per-user limits of expensive actions (docs/DESIGN.md §9). Every limiter has a per-minute burst limit
    // and an hourly budget; the web form and /api/v1 route of an action share one counter.
    'rate_limits' => [
        'generation_per_minute' => (int) env('RATE_LIMIT_GENERATION_PER_MINUTE', 10),
        'generation_per_hour' => (int) env('RATE_LIMIT_GENERATION_PER_HOUR', 60),
        'test_runs_per_minute' => (int) env('RATE_LIMIT_TEST_RUNS_PER_MINUTE', 10),
        'test_runs_per_hour' => (int) env('RATE_LIMIT_TEST_RUNS_PER_HOUR', 120),
        'experiments_per_minute' => (int) env('RATE_LIMIT_EXPERIMENTS_PER_MINUTE', 10),
        'experiments_per_hour' => (int) env('RATE_LIMIT_EXPERIMENTS_PER_HOUR', 20),
        'spec_uploads_per_minute' => (int) env('RATE_LIMIT_SPEC_UPLOADS_PER_MINUTE', 10),
        'spec_uploads_per_hour' => (int) env('RATE_LIMIT_SPEC_UPLOADS_PER_HOUR', 60),
        // Login: failed attempts per email+IP (per minute), and per IP across every email
        // (password spraying) within login_ip_decay_seconds.
        'login_per_email_ip' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'login_per_ip' => (int) env('LOGIN_MAX_ATTEMPTS_PER_IP', 20),
        'login_ip_decay_seconds' => (int) env('LOGIN_IP_DECAY_SECONDS', 600),
    ],

    // Password for the bootstrap ADMIN created by AdminUserSeeder. Empty means the seeder
    // generates a random password and prints it once; there is no well-known default.
    'admin_default_password' => (string) env('ADMIN_DEFAULT_PASSWORD', ''),

    // Password for the demo TESTER (demo@ait.local) created by DemoSeeder (S14-01, make demo).
    // Empty means the seeder generates a random password and prints it once.
    'demo_password' => (string) env('DEMO_PASSWORD', ''),

    // Public demo showcase (S14-05, ADR-0007 addendum 2026-10-13). Off by default everywhere. When on,
    // `php artisan ait:demo:showcase` creates/restores the shared, publicly known demo accounts
    // (App\Support\Demo\DemoShowcase, password 123456) and the demo data, the login page lists the
    // accounts. A visitor may still change them in M03: they are restored on every deploy and nightly.
    'demo_showcase' => [
        'enabled' => filter_var(env('DEMO_SHOWCASE_ENABLED', false), FILTER_VALIDATE_BOOL),
        // Compact fixture derived from benchmark/results/benchmark-v1.0 (php artisan ait:demo:build-fixture),
        // shipped in the production image instead of the database dumps.
        'fixture_path' => database_path('demo/benchmark-v1.0.json.gz'),
    ],

];
