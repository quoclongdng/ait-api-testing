<?php

namespace App\Contracts;

use App\Data\TargetRequest;
use App\Data\TargetResponse;

/**
 * Sends a single HTTP request to a system under test (target API).
 *
 * The Test Executor uses this contract; it must never be called with a URL
 * outside the allowed-targets list (see docs/DESIGN.md D07).
 * Implementations: LaravelTargetHttpClient (real), FakeTargetHttpClient (tests).
 * Selected by config('ait.target_client.driver').
 */
interface TargetHttpClient
{
    public function send(TargetRequest $request): TargetResponse;
}
