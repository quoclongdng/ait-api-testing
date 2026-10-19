<?php

namespace App\Services\Target;

use App\Contracts\TargetHostResolver;

final class FakeTargetHostResolver implements TargetHostResolver
{
    /** @var list<string> */
    public array $resolvedHosts = [];

    /** @param array<string, list<string>> $addresses */
    public function __construct(private array $addresses = []) {}

    public function resolve(string $host): array
    {
        $this->resolvedHosts[] = $host;

        return $this->addresses[$host] ?? [];
    }
}
