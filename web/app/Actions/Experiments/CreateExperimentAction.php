<?php

namespace App\Actions\Experiments;

use App\Actions\Generation\ResolveLlmAvailabilityAction;
use App\Contracts\BenchmarkSandbox;
use App\Enums\ErrorCode;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationStrategy;
use App\Enums\QueueName;
use App\Exceptions\ApiException;
use App\Jobs\RunExperimentJob;
use App\Models\Benchmark;
use App\Models\Experiment;
use App\Models\User;
use App\Services\Benchmark\GroundTruthLoader;
use App\Services\Experiments\ExperimentSeedPlanner;
use Illuminate\Support\Facades\DB;

/**
 * Plans an experiment (strategy × benchmark × model/seed/temperature × repetitions) and queues
 * it for the worker (docs/DESIGN.md §4.4, D10).
 */
class CreateExperimentAction
{
    public const DISABLED_MESSAGE = 'Bản triển khai này không hỗ trợ chạy thực nghiệm: '
        .'máy chủ chỉ có 3 benchmark sạch, không có sandbox seeded bug. Bạn vẫn xem được các thực nghiệm đã có; '
        .'thực nghiệm đầy đủ được chạy trên môi trường cục bộ của nhóm nghiên cứu.';

    /**
     * Vietnamese reason why experiments cannot be created on this deployment (ADR-0007), or null
     * when they can. Shown on M15 and returned by POST /api/v1/experiments.
     */
    public static function unavailableReason(): ?string
    {
        return (bool) config('ait.experiments.enabled', true) ? null : self::DISABLED_MESSAGE;
    }

    public function __construct(
        private readonly BenchmarkSandbox $sandbox,
        private readonly GroundTruthLoader $loader,
        private readonly ExperimentSeedPlanner $seedPlanner,
    ) {}

    /**
     * @param  array{name: string, benchmark_id: int, strategy: string, config: array{model?: string|null, seed: int, seeds?: list<int>|null, temperature: float|int|string, repetitions: int}}  $data
     *
     * @throws ApiException
     */
    public function execute(User $user, array $data): Experiment
    {
        // ADR-0007: a deployment without bug sandboxes refuses every new experiment.
        $disabled = self::unavailableReason();
        if ($disabled !== null) {
            throw new ApiException(ErrorCode::ValidationFailed, $disabled, ['experiments' => [$disabled]]);
        }

        // ADR-0007: without an LLM on this server only RULE experiments can run.
        app(ResolveLlmAvailabilityAction::class)->assertStrategyAvailable(GenerationStrategy::from($data['strategy']));

        $benchmark = Benchmark::query()->withCount('bugs')->findOrFail($data['benchmark_id']);

        if ((int) $benchmark->getAttribute('bugs_count') === 0) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Benchmark chưa có seeded bug nào. Hãy đồng bộ ground truth trước khi chạy thực nghiệm.',
                ['benchmark_id' => ['Benchmark chưa có seeded bug.']]
            );
        }

        if ($this->loader->openApiContent($benchmark->slug) === null) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                "Không tìm thấy benchmark/{$benchmark->slug}/openapi.yaml để sinh test.",
                ['benchmark_id' => ['Benchmark không có tệp OpenAPI.']]
            );
        }

        $this->sandbox->ensureAvailable($benchmark);

        $model = $data['config']['model'] ?? null;
        $repetitions = (int) $data['config']['repetitions'];
        $explicitSeeds = $data['config']['seeds'] ?? null;
        // experiment-design.md §4.2: repetition r uses seeds[r-1] (protocol 42, 1024, 2026).
        $seeds = $this->seedPlanner->plan($repetitions, (int) $data['config']['seed'], $explicitSeeds);
        $config = [
            'model' => is_string($model) && $model !== '' ? $model : null,
            'seed' => $seeds[0],
            'seeds' => $seeds,
            'temperature' => (float) $data['config']['temperature'],
            'repetitions' => $repetitions,
            // ADR-0006 addendum 2026-10-12: reset the sandbox before every test case (case isolation).
            'reset_per_case' => (bool) config('ait.experiments.reset_per_case', true),
            ...$this->fixtureSnapshot($benchmark),
        ];

        return DB::transaction(function () use ($user, $data, $benchmark, $config): Experiment {
            $experiment = Experiment::create([
                'name' => $data['name'],
                'benchmark_id' => $benchmark->id,
                'strategy' => GenerationStrategy::from($data['strategy']),
                'config' => $config,
                'status' => ExperimentStatus::Planned,
                'metrics' => null,
                'repetitions_data' => null,
            ]);

            RunExperimentJob::dispatch($experiment->id, $user->id)->onQueue(QueueName::Llm)->afterCommit();

            return $experiment;
        });
    }

    /**
     * ADR-0006: the auth fixture (synthetic sandbox tokens) and business rules of ground-truth.yaml
     * as they are now. Every later step reads only this snapshot, so editing the ground truth or the
     * shared experiment project cannot change a running or repeated experiment.
     *
     * @return array{auth: array<string, mixed>|null, business_rules: list<array{code: string, description: string, method: string|null, path: string|null}>}
     *
     * @throws ApiException
     */
    private function fixtureSnapshot(Benchmark $benchmark): array
    {
        try {
            $groundTruth = $this->loader->load($benchmark->slug);
        } catch (ApiException $e) {
            if ($e->errorCode === ErrorCode::NotFound) {
                // Benchmark registered without a ground-truth file: nothing to snapshot.
                return ['auth' => null, 'business_rules' => []];
            }

            throw new ApiException(ErrorCode::ValidationFailed, $e->getMessage(), ['benchmark_id' => [$e->getMessage()]]);
        }

        return [
            'auth' => $groundTruth->auth?->toArray(),
            'business_rules' => $groundTruth->businessRules,
        ];
    }
}
