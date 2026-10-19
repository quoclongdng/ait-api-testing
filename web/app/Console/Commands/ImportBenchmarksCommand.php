<?php

namespace App\Console\Commands;

use App\Actions\Benchmarks\AllowBenchmarkTargetsAction;
use App\Actions\Benchmarks\ImportGroundTruthAction;
use App\Actions\Experiments\ResolveExperimentOperatorAction;
use App\Exceptions\ApiException;
use Illuminate\Console\Command;

/**
 * php artisan ait:benchmarks:import [slug...] [--allow-targets] [--user=<email>]
 */
class ImportBenchmarksCommand extends Command
{
    protected $signature = 'ait:benchmarks:import
        {slugs?* : Benchmark slugs (default: every benchmark/<slug>/ground-truth.yaml)}
        {--allow-targets : Thêm host của biến thể sạch và từng biến thể bug vào allowed_targets (D07)}
        {--user= : Email người tạo các dòng allowed_targets (mặc định: ADMIN đầu tiên)}';

    protected $description = 'Đồng bộ benchmark và seeded bug từ benchmark/<slug>/ground-truth.yaml (M14)';

    public function handle(
        ImportGroundTruthAction $action,
        AllowBenchmarkTargetsAction $allowTargets,
        ResolveExperimentOperatorAction $resolveUser,
    ): int {
        /** @var list<string> $slugs */
        $slugs = array_values(array_map('strval', (array) $this->argument('slugs')));

        try {
            $results = $action->execute($slugs === [] ? null : $slugs);
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($results === []) {
            $this->warn('Không tìm thấy ground-truth.yaml nào.');

            return self::SUCCESS;
        }

        $this->table(
            ['Benchmark', 'ID', 'Mới tạo', 'Bug thêm', 'Bug cập nhật', 'Bug không còn trong tệp'],
            array_map(fn (array $r): array => [
                $r['slug'],
                $r['benchmark_id'],
                $r['created'] ? 'có' : 'không',
                $r['bugs_created'],
                $r['bugs_updated'],
                $r['stale_bugs'] === [] ? '—' : implode(', ', $r['stale_bugs']),
            ], $results),
        );

        if (! (bool) $this->option('allow-targets')) {
            return self::SUCCESS;
        }

        try {
            $user = $resolveUser->execute($this->stringOption('user'));
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = $allowTargets->execute($user, array_map(fn (array $r): int => $r['benchmark_id'], $results));

        $this->table(
            ['Benchmark', 'Host', 'Port', 'Thêm mới'],
            array_map(fn (array $r): array => [$r['benchmark'], $r['host'], $r['port'], $r['created'] ? 'có' : 'đã có'], $rows),
        );

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
