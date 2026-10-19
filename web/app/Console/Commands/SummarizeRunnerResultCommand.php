<?php

namespace App\Console\Commands;

use App\Actions\Experiments\SummarizeRunnerResultAction;
use App\Exceptions\ApiException;
use Illuminate\Console\Command;

/**
 * php artisan ait:experiments:summarize-runner benchmark/results/raw/<file>.json
 */
class SummarizeRunnerResultCommand extends Command
{
    protected $signature = 'ait:experiments:summarize-runner {files* : Raw JSON result files of benchmark/runner}';

    protected $description = 'Tính lại chỉ số §11 (mean ± stddev) từ tệp kết quả của experiment runner Python (S9-01)';

    public function handle(SummarizeRunnerResultAction $action): int
    {
        $rows = [];
        foreach ((array) $this->argument('files') as $file) {
            $file = (string) $file;
            if (! is_file($file)) {
                $this->error("Không tìm thấy tệp: {$file}");

                return self::FAILURE;
            }

            try {
                $summary = $action->execute((string) file_get_contents($file));
            } catch (ApiException $e) {
                $this->error("{$file}: {$e->getMessage()}");

                return self::FAILURE;
            }

            $row = [$summary['benchmark'], $summary['strategy'], $summary['status'], $summary['repetitions']];
            foreach (SummarizeRunnerResultAction::METRICS as $key) {
                $row[] = sprintf('%.1f%% ± %.1f', $summary['metrics'][$key] * 100, $summary['stddev'][$key] * 100);
            }
            $rows[] = $row;
        }

        $this->table(['Benchmark', 'Chiến lược', 'Trạng thái', 'Số lần lặp', 'Executable', 'False Positive', 'Bug Detection', 'Mutation Score'], $rows);

        return self::SUCCESS;
    }
}
