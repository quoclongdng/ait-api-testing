<?php

namespace App\Console\Commands;

use App\Actions\Experiments\GetExperimentStatusesAction;
use App\Enums\ExperimentStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * php artisan ait:experiments:wait [ids...] [--interval=10] [--timeout=0]
 *
 * Polls until every given experiment (default: every PLANNED/RUNNING experiment at start) is
 * COMPLETED or FAILED. Exit code 0 only when all of them COMPLETED.
 */
class WaitExperimentsCommand extends Command
{
    protected $signature = 'ait:experiments:wait
        {ids?* : ID thực nghiệm (có thể phân cách bằng dấu phẩy); mặc định: mọi thực nghiệm PLANNED/RUNNING}
        {--interval=10 : Số giây giữa hai lần kiểm tra}
        {--timeout=0 : Số giây chờ tối đa (0 = không giới hạn)}';

    protected $description = 'Chờ các thực nghiệm chạy xong (S11-02, make experiment)';

    public function handle(GetExperimentStatusesAction $statuses): int
    {
        $ids = [];
        foreach ((array) $this->argument('ids') as $arg) {
            foreach (explode(',', $arg) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (preg_match('/^[1-9][0-9]*$/', $part) !== 1) {
                    $this->error("ID thực nghiệm không hợp lệ: {$part}");

                    return self::FAILURE;
                }
                $ids[] = (int) $part;
            }
        }
        $interval = max(0, (int) $this->option('interval'));
        $timeout = max(0, (int) $this->option('timeout'));

        $watched = $ids === [] ? array_keys($statuses->execute(null)) : array_values(array_unique($ids));
        if ($watched === []) {
            $this->info('Không có thực nghiệm nào đang chờ chạy.');

            return self::SUCCESS;
        }

        $elapsed = 0;
        $last = [];
        while (true) {
            $current = $statuses->execute($watched);
            $missing = array_diff($watched, array_keys($current));
            if ($missing !== []) {
                $this->error('Không tìm thấy thực nghiệm: '.implode(', ', $missing));

                return self::FAILURE;
            }

            $pending = 0;
            foreach ($current as $id => $row) {
                $line = "#{$id} {$row['name']}: {$row['status']->value} (lần lặp đã sinh: {$row['progress']})";
                if (($last[$id] ?? null) !== $line) {
                    $this->line($line);
                    $last[$id] = $line;
                }
                if (in_array($row['status'], [ExperimentStatus::Planned, ExperimentStatus::Running], true)) {
                    $pending++;
                }
            }

            if ($pending === 0) {
                $failed = array_filter($current, fn (array $row): bool => $row['status'] === ExperimentStatus::Failed);
                foreach ($failed as $id => $row) {
                    $this->error("Thực nghiệm #{$id} FAILED: ".($row['error'] ?? 'không rõ lý do'));
                }
                if ($failed === []) {
                    $this->info('Mọi thực nghiệm đã COMPLETED.');
                }

                return $failed === [] ? self::SUCCESS : self::FAILURE;
            }

            if ($timeout > 0 && $elapsed >= $timeout) {
                $this->error("Hết thời gian chờ ({$timeout} giây), còn {$pending} thực nghiệm chưa xong.");

                return self::FAILURE;
            }

            Sleep::for(max(1, $interval))->seconds();
            $elapsed += max(1, $interval);
        }
    }
}
