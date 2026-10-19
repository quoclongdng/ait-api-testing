<?php

namespace App\Console\Commands;

use App\Actions\Benchmarks\ImportGroundTruthAction;
use App\Actions\Demo\ImportOfficialExperimentsAction;
use App\Actions\Demo\RestoreDemoAccountsAction;
use App\Actions\Demo\SeedBenchmarkDemoProjectAction;
use App\Data\Demo\DemoProjectPlan;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Demo\DemoShowcaseFixture;
use App\Support\Demo\DemoShowcase;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan ait:demo:showcase [--restore-accounts]
 *
 * Public demo showcase of the production site (S14-05, ADR-0007 addendum 2026-10-13). Only runs when
 * DEMO_SHOWCASE_ENABLED=true. Idempotent: server-deploy.sh runs it on every deploy, cron runs
 * `--restore-accounts` every night.
 */
class DemoShowcaseCommand extends Command
{
    protected $signature = 'ait:demo:showcase
        {--restore-accounts : Chỉ khôi phục 4 tài khoản demo (mật khẩu, vai trò, mở khóa, đăng xuất phiên cũ)}';

    protected $description = 'Tạo/khôi phục tài khoản demo công khai và dữ liệu mẫu đầy đủ (chỉ khi DEMO_SHOWCASE_ENABLED=true)';

    public function handle(
        RestoreDemoAccountsAction $restore,
        ImportGroundTruthAction $importBenchmarks,
        SeedBenchmarkDemoProjectAction $seedProject,
        ImportOfficialExperimentsAction $importExperiments,
        DemoShowcaseFixture $fixture,
    ): int {
        if (! DemoShowcase::enabled()) {
            $this->error('Chưa bật DEMO_SHOWCASE_ENABLED=true trong web/.env: không tạo tài khoản hay dữ liệu demo.');

            return self::FAILURE;
        }

        $accounts = $restore->execute();
        $this->table(['Tài khoản demo', 'Vai trò', 'Trạng thái'], array_map(static fn (array $a): array => [
            $a['email'],
            $a['role'],
            $a['created'] ? 'mới tạo' : ($a['changes'] === [] ? 'đúng chuẩn' : 'đã khôi phục: '.implode(', ', $a['changes'])),
        ], $accounts));

        if ((bool) $this->option('restore-accounts')) {
            return self::SUCCESS;
        }

        $failed = false;
        try {
            $benchmarks = $importBenchmarks->execute(array_column(DemoShowcase::projects(), 'slug'));
            $this->info('Benchmark: '.count($benchmarks).' bộ, '.array_sum(array_map(static fn (array $b): int => $b['bugs_created'] + $b['bugs_updated'], $benchmarks)).' bug thêm/cập nhật.');
        } catch (Throwable $e) {
            $this->error('Không import được benchmark: '.$e->getMessage());

            return self::FAILURE;
        }

        /** @var array<string, TestSuite> $anchors */
        $anchors = [];
        foreach (DemoShowcase::projects() as $project) {
            $owner = User::query()->where('email', $project['owner'])->firstOrFail();
            $slug = $project['slug'];
            $plan = new DemoProjectPlan(
                benchmarkSlug: $slug,
                projectName: $project['name'],
                projectDescription: "Dự án mẫu trên benchmark {$slug} (benchmark-v1.0): đặc tả, quy tắc nghiệp vụ, suite RULE sinh trực tiếp và suite LLM/HYBRID lấy từ thực nghiệm chính thức.",
                specVersion: DemoShowcase::SPEC_VERSION,
                ruleSuiteName: DemoShowcase::RULE_SUITE_NAME,
                ruleSuiteDescription: 'Test case sinh theo luật trên mọi endpoint, chạy trên bản sạch của benchmark.',
                ruleMaxCasesPerEndpoint: DemoShowcase::RULE_MAX_CASES_PER_ENDPOINT,
                ruleRunResetPerCase: true,
                recordedSuiteName: DemoShowcase::RECORDED_SUITE_NAME,
                recordedSuiteDescription: DemoShowcase::recordedSuiteDescription(),
                recordedStrategies: DemoShowcase::RECORDED_STRATEGIES,
                recordedJobs: static fn (): array => $fixture->recordedJobs($slug),
                recordedRunUsesAuthFixture: true,
                retryHint: 'Chạy lại: php artisan ait:demo:showcase',
                legacySuiteDescriptions: DemoShowcase::LEGACY_SUITE_DESCRIPTIONS,
            );

            try {
                $result = $seedProject->execute($owner, $plan);
            } catch (Throwable $e) {
                $failed = true;
                $this->error("Dự án demo {$project['name']}: ".$e->getMessage());

                continue;
            }
            $anchors[$slug] = $result->suites[0];
            $this->info("Dự án demo: {$project['name']} (#{$result->project->id}, chủ sở hữu {$owner->email})");
            foreach ($result->warnings as $warning) {
                $this->warn('  '.$warning);
            }
        }

        if ($anchors !== []) {
            try {
                $admin = User::query()->where('email', DemoShowcase::ADMIN_EMAIL)->firstOrFail();
                $experiments = $importExperiments->execute($admin, $anchors);
                $created = count(array_filter($experiments, static fn (array $e): bool => $e['created']));
                $this->info('Thực nghiệm chính thức (chỉ xem): '.count($experiments)." ({$created} mới nhập).");
            } catch (Throwable $e) {
                $failed = true;
                $this->error('Không nhập được thực nghiệm chính thức: '.$e->getMessage());
            }
        }

        $this->newLine();
        $this->line('Tài khoản demo công khai (mật khẩu '.DemoShowcase::PASSWORD.'): '.implode(', ', DemoShowcase::emails()));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
