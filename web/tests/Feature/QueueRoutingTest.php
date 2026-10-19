<?php

namespace Tests\Feature;

use App\Actions\Generation\CreateGenerationJobAction;
use App\Enums\ApiSpecStatus;
use App\Enums\QueueName;
use App\Jobs\GenerateTestsJob;
use App\Models\ApiSpec;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * S14-06 QA: every job went to the single `default` queue, so one long LLM/HYBRID generation (minutes
 * against a rate-limited provider) held up RULE generation and test runs of every user. LLM/HYBRID
 * generation and experiments now go to the `llm` queue; RULE generation and runs stay on `default`
 * (also asserted in RuleGenerationJobTest, TestRunApiTest, ExperimentApiTest and RunExperimentTest).
 */
class QueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function llmStrategies(): array
    {
        return ['LLM' => ['LLM'], 'HYBRID' => ['HYBRID']];
    }

    #[DataProvider('llmStrategies')]
    public function test_llm_and_hybrid_generation_jobs_go_to_the_llm_queue(string $strategy): void
    {
        Queue::fake();
        $user = User::factory()->tester()->create();
        $spec = ApiSpec::factory()->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create(['api_spec_id' => $spec->id]);

        $job = app(CreateGenerationJobAction::class)->execute($user, $suite, ['strategy' => $strategy]);

        Queue::assertPushedOn(QueueName::Llm->value, GenerateTestsJob::class, fn (GenerateTestsJob $queued): bool => $queued->generationJobId === $job->id);
    }

    public function test_queue_names_match_the_worker_commands(): void
    {
        $this->assertSame('default', QueueName::Default->value);
        $this->assertSame('llm', QueueName::Llm->value);

        // `composer dev` (outside Docker) listens on both queues too, or LLM/HYBRID jobs would never run.
        $composer = (string) file_get_contents(base_path('composer.json'));
        $this->assertStringContainsString('queue:listen --queue=default,llm', $composer);

        $path = base_path('../docker/docker-compose.yml');
        if (! is_file($path)) {
            $this->markTestSkipped('docker/docker-compose.yml is not mounted (running inside the web container).');
        }
        // One development worker serves both queues, default first (make up, make experiment).
        $this->assertStringContainsString('"queue:work", "--queue=default,llm"', (string) file_get_contents($path));
    }
}
