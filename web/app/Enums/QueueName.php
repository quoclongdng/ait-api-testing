<?php

namespace App\Enums;

/**
 * Queues of the `database` connection (D10, S14-06).
 *
 * - Default: short jobs a user waits for: RULE generation and test runs (M11, M12).
 * - Llm: long LLM/HYBRID generation (up to AI_GENERATE_BUDGET_SECONDS against a rate-limited provider)
 *   and experiments, so they never hold up the default queue.
 *
 * Development and `make experiment` run one worker on `--queue=default,llm`; production runs `worker`
 * on `default` and `worker-llm` on `llm` (docker/docker-compose.prod.yml).
 */
enum QueueName: string
{
    case Default = 'default';
    case Llm = 'llm';
}
