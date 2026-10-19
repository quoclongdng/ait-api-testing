<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Regression (S13-03 QA): the dev `vite` service runs `npm run build -- --watch`. app.css scanned the
 * compiled views in storage/framework/views, so every newly compiled Blade view started a rebuild, and
 * each rebuild emptied public/build: a page loaded in that window had no manifest.json and showed the
 * assets-missing notice. server.watch.ignored does not apply to `vite build --watch`.
 */
class ViteWatchConfigTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3).'/'.$relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_tailwind_does_not_scan_compiled_views(): void
    {
        $css = $this->read('resources/css/app.css');
        $sources = preg_match_all('/^@source\s+[\'"]([^\'"]+)[\'"]/m', $css, $matches) ? $matches[1] : [];

        $this->assertContains('../**/*.blade.php', $sources, 'Blade sources must stay scanned');
        foreach ($sources as $source) {
            $this->assertStringNotContainsString('storage/framework/views', $source);
        }
    }

    public function test_watch_build_keeps_the_previous_output_and_ignores_compiled_views(): void
    {
        $config = $this->read('vite.config.js');

        $this->assertMatchesRegularExpression("/const watching = process\.argv\.includes\('--watch'\)/", $config);
        $this->assertStringContainsString('emptyOutDir: !watching', $config);
        $this->assertMatchesRegularExpression(
            "/watch: watching \? \{ exclude: \[[^\]]*'\*\*\/storage\/framework\/views\/\*\*'/",
            $config,
        );
    }

    public function test_dev_compose_still_runs_the_watch_build(): void
    {
        $path = dirname(__DIR__, 4).'/docker/docker-compose.yml';
        if (! is_file($path)) {
            $this->markTestSkipped('docker/docker-compose.yml is not mounted (running inside the web container).');
        }

        $this->assertStringContainsString('npm run build -- --watch', (string) file_get_contents($path));
    }
}
