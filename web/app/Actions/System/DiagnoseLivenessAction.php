<?php

namespace App\Actions\System;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Throwable;

/**
 * Liveness check behind `GET /up` (S14-06): fires the framework DiagnosingHealth event, like the
 * stock health route did, and reports a failing listener to the log. The cause never leaves the
 * server: the caller only learns whether the application is up.
 */
final class DiagnoseLivenessAction
{
    public function __construct(private readonly Dispatcher $events) {}

    public function execute(): bool
    {
        try {
            $this->events->dispatch(new DiagnosingHealth);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
