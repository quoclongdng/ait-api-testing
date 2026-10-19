<?php

namespace App\Console\Commands;

use App\Actions\Demo\BuildDemoShowcaseFixtureAction;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

/**
 * php artisan ait:demo:build-fixture [--output=<file>]
 *
 * Regenerates web/database/demo/benchmark-v1.0.json.gz from benchmark/results/benchmark-v1.0 (S14-05).
 * Run on a development checkout (the results are not in the production image); commit the output.
 */
class BuildDemoFixtureCommand extends Command
{
    protected $signature = 'ait:demo:build-fixture
        {--output= : Tệp đích (mặc định: config ait.demo_showcase.fixture_path)}';

    protected $description = 'Tạo fixture gọn của trang demo từ kết quả chính thức benchmark-v1.0';

    public function handle(BuildDemoShowcaseFixtureAction $action): int
    {
        $output = $this->option('output');
        $path = is_string($output) && $output !== '' ? $output : (string) config('ait.demo_showcase.fixture_path');

        try {
            $content = $action->encode();
        } catch (RuntimeException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            $this->error('Không tạo được thư mục '.dirname($path).'.');

            return self::FAILURE;
        }
        if (file_put_contents($path, $content) === false) {
            $this->error("Không ghi được {$path}.");

            return self::FAILURE;
        }

        $this->info(sprintf('Đã ghi %s (%d KB).', $path, (int) ceil(strlen($content) / 1024)));

        return self::SUCCESS;
    }
}
