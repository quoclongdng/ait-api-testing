<?php

namespace App\Contracts;

interface TargetHostResolver
{
    /** @return list<string> Resolved IPv4/IPv6 addresses, or [] when resolution fails. */
    public function resolve(string $host): array;
}
