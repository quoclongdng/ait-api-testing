<?php

namespace App\Actions\System;

use App\Contracts\AiGeneratorClient;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CheckHealthAction
{
    public function __construct(private readonly AiGeneratorClient $aiClient) {}

    /**
     * @return array{status: 'ok'|'degraded', checks: array{database: bool, ai_service: array{reachable: bool, provider: ?string, version: ?string}}}
     */
    public function execute(): array
    {
        $database = $this->databaseIsUp();
        $ai = $this->aiClient->status();

        return [
            'status' => $database && $ai->reachable ? 'ok' : 'degraded',
            'checks' => [
                'database' => $database,
                'ai_service' => $ai->toArray(),
            ],
        ];
    }

    private function databaseIsUp(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
