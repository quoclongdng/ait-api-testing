<?php

namespace Tests\Feature\Deployment;

use App\Actions\System\ResolvePublicAssetAction;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * QA infra-deploy (S13-03): in production `php -S` served public/ files itself, without the security
 * headers and without the Host check. docker/web/php/router.php now sends every request to Laravel,
 * which serves the allowlisted public files (PublicAssetController) through the global middleware.
 */
class PublicAssetTest extends TestCase
{
    private string $public;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);

        $base = sys_get_temp_dir().'/ait-public-asset-'.bin2hex(random_bytes(4));
        $this->public = $base.'/public';
        $this->outside = $base.'/outside';
        mkdir($this->public.'/build/assets', 0777, true);
        mkdir($this->outside, 0777, true);
        file_put_contents($this->public.'/build/assets/app-C0gheG0L.css', 'body{color:red}');
        file_put_contents($this->public.'/build/assets/app-Dx1.js', 'console.log(1)');
        file_put_contents($this->public.'/build/manifest.json', '{"resources/css/app.css":{"file":"assets/app-C0gheG0L.css"}}');
        file_put_contents($this->public.'/robots.txt', "User-agent: *\nDisallow:\n");
        file_put_contents($this->public.'/favicon.ico', '');
        file_put_contents($this->public.'/.htaccess', 'RewriteEngine On');
        file_put_contents($this->public.'/build/secret.php', '<?php echo "source";');
        file_put_contents($this->public.'/build/notes.md', '# notes');
        file_put_contents($this->outside.'/leak.txt', 'outside public');
        symlink($this->outside.'/leak.txt', $this->public.'/build/leak.txt');

        $this->app->usePublicPath($this->public);
    }

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        (new Filesystem)->deleteDirectory(dirname($this->public));
        parent::tearDown();
    }

    public function test_build_assets_carry_the_security_headers_and_a_long_cache(): void
    {
        $response = $this->get('/build/assets/app-C0gheG0L.css')->assertOk();

        $this->assertSame('body{color:red}', $response->streamedContent());
        $response->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('X-Request-Id')
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Set-Cookie');
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));

        $this->get('/build/assets/app-Dx1.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=UTF-8');
    }

    public function test_robots_favicon_and_manifest_are_served_with_explicit_types(): void
    {
        $robots = $this->get('/robots.txt')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->assertStringNotContainsString('immutable', (string) $robots->headers->get('Cache-Control'));
        $this->assertStringContainsString('Disallow', (string) $robots->streamedContent());

        $this->get('/favicon.ico')->assertOk()->assertHeader('Content-Type', 'image/x-icon');
        $this->get('/build/manifest.json')->assertOk()->assertHeader('Content-Type', 'application/json');
    }

    public function test_hsts_is_sent_on_static_files_over_https(): void
    {
        config(['ait.security.hsts_max_age' => 31536000]);

        $this->get('https://localhost/robots.txt')->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->get('http://localhost/robots.txt')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_in_production_a_foreign_host_is_refused_for_static_files(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://aitest.example.test']);

        $this->get('http://evil.example.test/robots.txt')->assertStatus(400)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('http://evil.example.test/build/assets/app-C0gheG0L.css')->assertStatus(400);

        $this->get('http://aitest.example.test/robots.txt')->assertOk();
        $this->get('http://127.0.0.1:8000/build/manifest.json')->assertOk();
    }

    public function test_dot_files_php_sources_unknown_types_and_directories_are_not_served(): void
    {
        foreach (['/.htaccess', '/index.php', '/build/secret.php', '/build/notes.md', '/build/assets', '/build/', '/build/missing.css', '/build/leak.txt'] as $uri) {
            $response = $this->get($uri);
            $response->assertNotFound();
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringNotContainsString('outside public', (string) $response->getContent(), $uri);
            $this->assertStringNotContainsString('RewriteEngine', (string) $response->getContent(), $uri);
        }
    }

    public function test_the_resolver_refuses_traversal_and_files_outside_public(): void
    {
        $resolver = app(ResolvePublicAssetAction::class);

        $this->assertNull($resolver->execute('build/../.htaccess'));
        $this->assertNull($resolver->execute('build/../../outside/leak.txt'));
        $this->assertNull($resolver->execute('build/./manifest.json'));
        $this->assertNull($resolver->execute('build//manifest.json'));
        $this->assertNull($resolver->execute('build\\manifest.json'));
        $this->assertNull($resolver->execute("robots.txt\0.css"));
        $this->assertNull($resolver->execute('build/leak.txt'));
        $this->assertNull($resolver->execute(''));

        $asset = $resolver->execute('build/manifest.json');
        $this->assertNotNull($asset);
        $this->assertSame(realpath($this->public.'/build/manifest.json'), $asset->absolutePath);
    }

    public function test_only_get_and_head_are_accepted(): void
    {
        $this->call('HEAD', '/robots.txt')->assertOk();
        $this->post('/robots.txt')->assertStatus(405);
        $this->delete('/build/manifest.json')->assertStatus(405)->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
