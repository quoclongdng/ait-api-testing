<?php

namespace App\Console\Commands;

use App\Actions\Benchmarks\AllowBenchmarkTargetsAction;
use App\Actions\Experiments\ResolveExperimentOperatorAction;
use App\Actions\Experiments\StartExperimentBatchAction;
use App\Exceptions\ApiException;
use App\Models\Experiment;
use Illuminate\Console\Command;

/**
 * php artisan ait:experiments:run --strategies=RULE,LLM,HYBRID --benchmarks=all --repetitions=3
 *     [--model=...] [--temperature=0.2] [--seeds=42,1024,2026] [--user=<email>]
 *
 * Creates and queues one experiment per benchmark × strategy (used by `make experiment`); the
 * worker runs them. Follow with `php artisan ait:experiments:wait <ids>`.
 */
class RunExperimentsCommand extends Command
{
    protected $signature = 'ait:experiments:run
        {--strategies=RULE,LLM,HYBRID : Chiến lược, phân cách bằng dấu phẩy}
        {--benchmarks=all : "all" hoặc danh sách slug phân cách bằng dấu phẩy}
        {--repetitions=3 : Số lần lặp mỗi cấu hình (3..10)}
        {--model= : Model LLM (trống = mặc định của ai-service)}
        {--temperature=0.2 : Temperature LLM}
        {--seeds= : Seed từng lần lặp, phân cách bằng dấu phẩy (trống = EXPERIMENT_SEEDS)}
        {--user= : Email người chạy (mặc định: ADMIN đầu tiên)}
        {--name=make experiment : Tiền tố tên thực nghiệm}
        {--skip-allow-targets : Không tự thêm host sandbox benchmark vào allowed_targets}';

    protected $description = 'Tạo và đưa vào hàng đợi thực nghiệm RULE/LLM/HYBRID trên các benchmark (S11-02)';

    public function handle(
        ResolveExperimentOperatorAction $resolveUser,
        AllowBenchmarkTargetsAction $allowTargets,
        StartExperimentBatchAction $start,
    ): int {
        $benchmarks = trim($this->stringOption('benchmarks') ?? 'all');
        $slugs = strtolower($benchmarks) === 'all' || $benchmarks === '' ? null : $this->csv($benchmarks);
        $seedList = $this->csv($this->stringOption('seeds') ?? '');
        foreach ($seedList as $seed) {
            if (preg_match('/^[0-9]+$/', $seed) !== 1) {
                $this->error("Seed không hợp lệ: {$seed}");

                return self::FAILURE;
            }
        }
        $temperature = $this->stringOption('temperature') ?? '0.2';
        $repetitions = $this->stringOption('repetitions') ?? '3';
        if (! is_numeric($temperature) || preg_match('/^[0-9]+$/', $repetitions) !== 1) {
            $this->error('--temperature phải là số và --repetitions phải là số nguyên.');

            return self::FAILURE;
        }

        try {
            $user = $resolveUser->execute($this->stringOption('user'));
            if (! (bool) $this->option('skip-allow-targets')) {
                $allowTargets->execute($user);
            }
            $experiments = $start->execute(
                user: $user,
                strategies: $this->csv($this->stringOption('strategies') ?? ''),
                slugs: $slugs,
                repetitions: (int) $repetitions,
                model: $this->stringOption('model'),
                temperature: (float) $temperature,
                seeds: $seedList === [] ? null : array_map('intval', $seedList),
                namePrefix: $this->stringOption('name') ?? 'make experiment',
            );
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['ID', 'Tên', 'Chiến lược', 'Seeds', 'Trạng thái'],
            array_map(fn (Experiment $e): array => [
                $e->id,
                $e->name,
                $e->strategy->value,
                implode(',', array_map(fn (mixed $s): string => is_scalar($s) ? (string) $s : '', (array) ($e->config['seeds'] ?? []))),
                $e->refresh()->status->value,
            ], $experiments),
        );
        $this->line('EXPERIMENT_IDS='.implode(',', array_map(fn (Experiment $e): int => $e->id, $experiments)));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v): bool => $v !== ''));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
