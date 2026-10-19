<?php

namespace Tests\Unit\Services\Experiments;

use App\Services\Experiments\ExperimentSeedPlanner;
use Tests\TestCase;

class ExperimentSeedPlannerTest extends TestCase
{
    public function test_protocol_seeds_are_used_per_repetition(): void
    {
        $planner = new ExperimentSeedPlanner([42, 1024, 2026]);

        $this->assertSame([42, 1024, 2026], $planner->plan(3));
        $this->assertSame([42, 1024, 2026], $planner->plan(3, 42));
        $this->assertSame([42], $planner->plan(1));
    }

    public function test_more_repetitions_than_protocol_seeds_continue_deterministically(): void
    {
        $planner = new ExperimentSeedPlanner([42, 1024, 2026]);

        $this->assertSame([42, 1024, 2026, 2027, 2028], $planner->plan(5));
    }

    public function test_extension_skips_seeds_already_used(): void
    {
        $this->assertSame([1, 3, 4, 5], (new ExperimentSeedPlanner([1, 3]))->plan(4));
        $this->assertSame([5, 6, 7], (new ExperimentSeedPlanner([5, 6]))->plan(3));
    }

    public function test_a_different_first_seed_replaces_repetition_one(): void
    {
        $planner = new ExperimentSeedPlanner([42, 1024, 2026]);

        $this->assertSame([7, 42, 1024], $planner->plan(3, 7));
        $this->assertSame([1024, 42, 2026], $planner->plan(3, 1024));
    }

    public function test_explicit_seeds_win_and_duplicates_are_removed(): void
    {
        $planner = new ExperimentSeedPlanner([42, 1024, 2026]);

        $this->assertSame([5, 9, 10], $planner->plan(3, 42, [5, 9]));
        $this->assertSame([5, 6, 7], $planner->plan(3, null, [5, 5, 6]));
        $this->assertSame([5], $planner->plan(1, null, [5, 9, 11]));
    }

    public function test_config_list_and_default_fallback(): void
    {
        config(['ait.experiments.seeds' => [11, 22, 33]]);
        $this->assertSame([11, 22, 33], (new ExperimentSeedPlanner)->plan(3));

        config(['ait.experiments.seeds' => []]);
        $this->assertSame(ExperimentSeedPlanner::DEFAULT_SEEDS, (new ExperimentSeedPlanner)->protocol());
    }

    public function test_default_configuration_follows_experiment_design(): void
    {
        $this->assertSame([42, 1024, 2026], config('ait.experiments.seeds'));
    }
}
