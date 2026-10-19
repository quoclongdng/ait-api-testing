<?php

namespace Tests\Feature\Deployment;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Compilers\BladeCompiler;
use Tests\TestCase;

/**
 * Regression (S13-03 QA final): the vendor stage of docker/web/Dockerfile.prod runs
 * `composer dump-autoload`, whose post-autoload-dump script boots the application for
 * package:discover while web/storage/framework/views/ is excluded from the build context. Registering
 * a Blade directive eagerly in AppServiceProvider::boot() resolved the Blade compiler there and the
 * build stopped with "Please provide a valid cache path.". Booting must not need the compiled-view
 * directory, and @invalid must still be available once Blade is used.
 */
class ProviderBootWithoutViewCacheTest extends TestCase
{
    public function test_application_boots_without_a_compiled_view_path_and_registers_invalid_lazily(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacadeApp = Facade::getFacadeApplication();

        try {
            /** @var Application $app */
            $app = require base_path('bootstrap/app.php');
            // Same value as config/view.php when storage/framework/views does not exist (realpath() false).
            $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
                $app['config']->set('view.compiled', false);
            });

            $app->make(ConsoleKernel::class)->bootstrap();

            $this->assertTrue($app->isBooted());
            $this->assertFalse($app->resolved('blade.compiler'), 'booting must not resolve the Blade compiler');

            $compiled = sys_get_temp_dir().'/ait-blade-'.bin2hex(random_bytes(4));
            mkdir($compiled);
            $app['config']->set('view.compiled', $compiled);
            /** @var BladeCompiler $blade */
            $blade = $app->make('blade.compiler');
            $this->assertArrayHasKey('invalid', $blade->getCustomDirectives());
            rmdir($compiled);
        } finally {
            Container::setInstance($previousContainer);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacadeApp);
        }
    }

    public function test_invalid_directive_still_renders_aria_attributes(): void
    {
        $html = $this->blade("<input @invalid('email', 'email-error')>", [
            'errors' => (new ViewErrorBag)->put('default', new MessageBag(['email' => ['Sai.']])),
        ]);

        $html->assertSee('aria-invalid="true"', false)->assertSee('aria-describedby="email-error"', false);
    }
}
