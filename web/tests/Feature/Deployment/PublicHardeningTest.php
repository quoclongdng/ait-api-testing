<?php

namespace Tests\Feature\Deployment;

use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\AllowedTarget;
use App\Models\ApiSpec;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Execution\TargetAllowlistValidator;
use App\Services\Target\FakeTargetHostResolver;
use App\Support\TrustedProxyList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * ADR-0007 public deployment behind Cloudflare → host nginx → 127.0.0.1:8091: trusted proxies and
 * the visitor IP, HTTPS URLs, security headers, error pages with trace id, INTERNAL_ERROR without
 * details, login throttling per IP and the target network policy.
 */
class PublicHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const NGINX = '172.18.0.1';

    private const CLOUDFLARE_EDGE = '162.158.10.20';

    private const VISITOR = '203.0.113.7';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);

        Route::get('/__test/ip', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'url' => url('/x'),
        ]));
        Route::middleware('web')->get('/__test/boom', fn () => throw new RuntimeException('SECRET-DETAIL-123 /var/www/html'));
        Route::get('/api/__test/boom', fn () => throw new RuntimeException('SECRET-DETAIL-123 select * from users'));
        Route::get('/api/__test/internal', fn () => throw new ApiException(ErrorCode::InternalError, 'SECRET-DETAIL-456', ['sql' => 'select 1']));
    }

    protected function tearDown(): void
    {
        // Symfony keeps the trusted host patterns in a static property.
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    // --- Trusted proxies and the visitor IP ---------------------------------------------------------

    public function test_without_trusted_proxies_forwarded_headers_are_ignored(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->viaProxy(self::VISITOR.', '.self::CLOUDFLARE_EDGE, self::VISITOR)
            ->assertJsonPath('ip', self::NGINX)
            ->assertJsonPath('secure', false);
    }

    public function test_behind_trusted_nginx_and_cloudflare_the_visitor_ip_and_https_are_used(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->viaProxy(self::VISITOR.', '.self::CLOUDFLARE_EDGE, self::VISITOR)
            ->assertJsonPath('ip', self::VISITOR)
            ->assertJsonPath('secure', true)
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_cf_connecting_ip_is_ignored_when_the_peer_is_not_a_cloudflare_edge(): void
    {
        // A client that bypasses Cloudflare and reaches nginx directly cannot choose its IP.
        config(['trustedproxy.proxies' => '*']);

        $this->viaProxy('198.51.100.9', '1.2.3.4')->assertJsonPath('ip', '198.51.100.9');
    }

    public function test_cf_connecting_ip_is_ignored_when_the_caller_is_not_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '10.9.9.9']);

        $this->viaProxy(self::CLOUDFLARE_EDGE, self::VISITOR)->assertJsonPath('ip', self::NGINX);
    }

    public function test_invalid_cf_connecting_ip_is_ignored(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->viaProxy(self::CLOUDFLARE_EDGE, 'not-an-ip')->assertJsonPath('ip', self::CLOUDFLARE_EDGE);
    }

    public function test_cloudflare_keyword_trusts_the_edge_ranges_through_x_forwarded_for(): void
    {
        config(['trustedproxy.proxies' => TrustedProxyList::parse('cloudflare,172.16.0.0/12')]);

        $this->viaProxy(self::VISITOR.', '.self::CLOUDFLARE_EDGE, null)->assertJsonPath('ip', self::VISITOR);
    }

    public function test_https_app_url_forces_https_links(): void
    {
        config(['app.url' => 'https://aitest.example.test']);
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', url('/login'));
    }

    // --- Host header injection -------------------------------------------------------------------------

    public function test_forwarded_host_port_and_prefix_are_ignored_even_from_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '*']);
        $evil = [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.example.test',
            'X-Forwarded-Port' => '4444',
            'X-Forwarded-Prefix' => '/evil',
        ];

        $this->withServerVariables(['REMOTE_ADDR' => self::NGINX])->withHeaders($evil)
            ->getJson('http://aitest.example.test/__test/ip')
            ->assertJsonPath('url', 'https://aitest.example.test/x')
            ->assertJsonPath('secure', true);

        $location = (string) $this->withServerVariables(['REMOTE_ADDR' => self::NGINX])->withHeaders($evil)
            ->get('http://aitest.example.test/dashboard')->assertRedirect()->headers->get('Location');
        $this->assertSame('https://aitest.example.test/login', $location);
    }

    public function test_in_production_a_foreign_host_is_refused_before_any_url_is_built(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://aitest.example.test']);

        $refused = $this->get('http://evil.example.test/dashboard')->assertStatus(400);
        $refused->assertHeaderMissing('Location');
        $this->assertStringNotContainsString('evil.example.test', (string) $refused->getContent());
        $this->getJson('http://evil.example.test/api/v1/health')->assertStatus(400);

        $this->assertSame('http://aitest.example.test/login', $this->get('http://AITEST.example.test/dashboard')->headers->get('Location'));
        // Docker healthcheck and server-deploy.sh reach the container directly.
        $this->get('http://127.0.0.1:8000/up')->assertOk();
        $this->get('http://localhost/up')->assertOk();
    }

    public function test_host_allowlist_is_not_applied_outside_production(): void
    {
        $this->get('http://evil.example.test/login')->assertOk();
    }

    // --- Login throttling per IP ----------------------------------------------------------------------

    public function test_failed_logins_are_throttled_per_ip_across_emails(): void
    {
        config(['ait.rate_limits.login_per_ip' => 3]);
        User::factory()->create(['email' => 'real@ait.local', 'password' => Hash::make('correct-password-1')]);

        foreach (['a@ait.local', 'b@ait.local', 'c@ait.local'] as $email) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
                ->post('/login', ['email' => $email, 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }

        // Even the right password of another account is refused from that IP now...
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/login', ['email' => 'real@ait.local', 'password' => 'correct-password-1'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        // ...while another IP is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21'])
            ->post('/login', ['email' => 'real@ait.local', 'password' => 'correct-password-1'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_visitors_behind_the_same_cloudflare_edge_have_separate_login_budgets(): void
    {
        config(['ait.rate_limits.login_per_ip' => 2, 'trustedproxy.proxies' => '*']);
        User::factory()->create(['email' => 'real@ait.local', 'password' => Hash::make('correct-password-1')]);

        foreach (['a@ait.local', 'b@ait.local'] as $email) {
            $this->viaProxyPost('203.0.113.50', ['email' => $email, 'password' => 'wrong']);
        }

        $this->viaProxyPost(self::VISITOR, ['email' => 'real@ait.local', 'password' => 'correct-password-1'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    // --- Security headers -----------------------------------------------------------------------------

    public function test_html_responses_carry_security_headers_and_a_nonce_csp(): void
    {
        $response = $this->get('/login')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Strict-Transport-Security');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+' 'unsafe-eval'/", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    public function test_inline_scripts_carry_the_csp_nonce(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create(['project_id' => $project->id, 'api_spec_id' => $spec->id, 'created_by' => $tester->id]);

        $response = $this->actingAs($tester)->get("/suites/{$suite->id}/generation-jobs/create")->assertOk();

        preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $match);
        $nonce = $match[1] ?? '';
        $this->assertNotSame('', $nonce);
        $response->assertSee('<script nonce="'.$nonce.'">', false);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*nonce=)[^>]*>/', (string) $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/\son(click|submit|change|load)=/', (string) $response->getContent());
    }

    public function test_json_responses_get_headers_but_no_csp(): void
    {
        $this->getJson('/api/v1/health')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Content-Security-Policy');
    }

    // --- Trace id and error pages ---------------------------------------------------------------------

    public function test_trace_id_is_echoed_when_safe_and_replaced_otherwise(): void
    {
        $this->withHeaders(['X-Request-Id' => 'abc-123.def'])->get('/login')->assertHeader('X-Request-Id', 'abc-123.def');

        $replaced = (string) $this->withHeaders(['X-Request-Id' => "evil\r\nSet-Cookie: x"])->get('/login')->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $replaced);
    }

    public function test_404_page_is_vietnamese_with_trace_id(): void
    {
        $response = $this->get('/khong-ton-tai-'.uniqid())->assertNotFound();

        $response->assertSee('Không tìm thấy trang')
            ->assertSee('Mã truy vết')
            ->assertSee((string) $response->headers->get('X-Request-Id'));
    }

    public function test_500_page_hides_the_exception_and_shows_trace_id(): void
    {
        $response = $this->get('/__test/boom')->assertStatus(500);

        $response->assertSee('Lỗi máy chủ')
            ->assertSee((string) $response->headers->get('X-Request-Id'))
            ->assertDontSee('SECRET-DETAIL-123')
            ->assertDontSee('RuntimeException')
            ->assertDontSee('/var/www');
    }

    public function test_api_internal_error_hides_details_unless_debug(): void
    {
        $response = $this->getJson('/api/__test/boom')->assertStatus(500);
        $response->assertJsonPath('error.code', 'INTERNAL_ERROR')
            ->assertJsonPath('error.message', __('Internal server error.'))
            ->assertJsonPath('trace_id', $response->headers->get('X-Request-Id'));
        $this->assertStringNotContainsString('SECRET-DETAIL', (string) $response->getContent());

        $hidden = $this->getJson('/api/__test/internal')->assertStatus(500);
        $hidden->assertJsonPath('error.message', __('Internal server error.'));
        $this->assertSame([], (array) $hidden->json('error.details'));

        config(['app.debug' => true]);
        $this->getJson('/api/__test/internal')->assertJsonPath('error.message', 'SECRET-DETAIL-456');
    }

    // --- Target network policy (D07 on a public server) -----------------------------------------------

    public function test_metadata_and_link_local_targets_are_always_refused(): void
    {
        config(['ait.target_client.block_private_networks' => false]);
        $admin = User::factory()->admin()->create();

        foreach (['169.254.169.254', 'fe80::1', '0.0.0.0', '::ffff:169.254.169.254'] as $host) {
            $this->actingAs($admin)->postJson('/api/v1/admin/allowed-targets', ['host' => $host])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'TARGET_NOT_ALLOWED');
        }
        // Private networks stay usable in local development (D07).
        $this->actingAs($admin)->postJson('/api/v1/admin/allowed-targets', ['host' => '10.0.0.5'])->assertCreated();
    }

    public function test_private_and_loopback_targets_are_refused_when_blocked(): void
    {
        config(['ait.target_client.block_private_networks' => true]);
        $admin = User::factory()->admin()->create();

        foreach (['10.0.0.5', '127.0.0.1', '192.168.1.10', 'localhost', 'api.localhost', '::1', '::ffff:127.0.0.1'] as $host) {
            $this->actingAs($admin)->postJson('/api/v1/admin/allowed-targets', ['host' => $host])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'TARGET_NOT_ALLOWED');
        }
        $this->actingAs($admin)->postJson('/api/v1/admin/allowed-targets', ['host' => 'api.example.test'])->assertCreated();
    }

    public function test_platform_services_are_refused_at_creation_but_benchmark_services_are_not(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([true, false] as $blockPrivate) {
            config(['ait.target_client.block_private_networks' => $blockPrivate]);
            foreach (['postgres', 'web', 'worker', 'ai-service', 'ollama', 'vite', 'AI-Service.', 'ait-postgres-1', 'ait-web-2'] as $host) {
                $this->actingAs($admin)->postJson('/api/v1/admin/allowed-targets', ['host' => $host])
                    ->assertStatus(422)
                    ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                    ->assertJsonPath('error.details.host.0', fn (string $m): bool => str_contains($m, 'dịch vụ nội bộ của nền tảng'));
            }
        }
        $this->assertSame(0, AllowedTarget::query()->count());

        // M04 form: the message is shown on the host field.
        $this->actingAs($admin)->from('/admin/allowed-targets')
            ->post('/admin/allowed-targets', ['host' => 'worker'])
            ->assertRedirect('/admin/allowed-targets')
            ->assertSessionHasErrors(['host' => '"worker" là dịch vụ nội bộ của nền tảng (cơ sở dữ liệu, web, worker, ai-service...), không phải API cần kiểm thử, nên không được thêm vào allowlist. Chỉ thêm host của API đích, ví dụ các dịch vụ benchmark.']);

        foreach (['benchmark', 'benchmark-order', 'simple-store', 'simple-store-ss-001', 'web.example.test', 'my-web', 'webshop'] as $host) {
            $this->actingAs($admin)->postJson('/api/v1/admin/allowed-targets', ['host' => $host, 'port' => 8000])->assertCreated();
        }
    }

    public function test_admin_added_hostname_resolving_to_a_private_address_is_refused_at_run_time(): void
    {
        config(['ait.target_client.block_private_networks' => true]);
        $admin = User::factory()->admin()->create();
        AllowedTarget::factory()->for($admin, 'creator')->create(['host' => 'rebound.example.test', 'port' => null]);
        AllowedTarget::factory()->for($admin, 'creator')->create(['host' => 'public.example.test', 'port' => null]);
        $validator = new TargetAllowlistValidator(
            customAllowedHosts: ['benchmark'],
            resolver: new FakeTargetHostResolver([
                'rebound.example.test' => ['10.1.2.3'],
                'public.example.test' => ['93.184.216.34'],
                'benchmark' => ['172.20.0.5'],
                'metadata.example.test' => ['169.254.169.254'],
            ]),
            dbTargetsResolver: fn (string $host) => AllowedTarget::query()->where('host', $host)->get(['port']),
        );

        $this->assertFalse($validator->isAllowed('http://rebound.example.test/x'));
        $this->assertTrue($validator->isAllowed('http://public.example.test/x'));
        // The internal benchmark services listed in ALLOWED_TARGET_HOSTS stay reachable.
        $this->assertTrue($validator->isAllowed('http://benchmark:8000/products'));

        config(['ait.target_client.block_private_networks' => false]);
        $this->assertTrue($validator->isAllowed('http://rebound.example.test/x'));
    }

    public function test_metadata_address_is_refused_even_for_a_configured_host(): void
    {
        $validator = new TargetAllowlistValidator(
            customAllowedHosts: ['metadata.example.test', '169.254.169.254'],
            resolver: new FakeTargetHostResolver(['metadata.example.test' => ['169.254.169.254']]),
        );

        $this->assertFalse($validator->isAllowed('http://metadata.example.test/latest/meta-data'));
        $this->assertFalse($validator->isAllowed('http://169.254.169.254/latest/meta-data'));
    }

    /**
     * @return TestResponse<Response>
     */
    private function viaProxy(?string $forwardedFor, ?string $cfConnectingIp): TestResponse
    {
        $headers = ['X-Forwarded-Proto' => 'https'];
        if ($forwardedFor !== null) {
            $headers['X-Forwarded-For'] = $forwardedFor;
        }
        if ($cfConnectingIp !== null) {
            $headers['CF-Connecting-IP'] = $cfConnectingIp;
        }

        return $this->withServerVariables(['REMOTE_ADDR' => self::NGINX])->withHeaders($headers)->getJson('/__test/ip')->assertOk();
    }

    /**
     * @param  array<string, string>  $data
     * @return TestResponse<Response>
     */
    private function viaProxyPost(string $visitor, array $data): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::NGINX])
            ->withHeaders([
                'X-Forwarded-For' => $visitor.', '.self::CLOUDFLARE_EDGE,
                'X-Forwarded-Proto' => 'https',
                'CF-Connecting-IP' => $visitor,
            ])
            ->post('/login', $data);
    }
}
