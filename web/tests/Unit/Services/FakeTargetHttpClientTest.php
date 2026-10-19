<?php

namespace Tests\Unit\Services;

use App\Data\TargetRequest;
use App\Services\Target\FakeTargetHttpClient;
use PHPUnit\Framework\TestCase;

class FakeTargetHttpClientTest extends TestCase
{
    public function test_it_returns_queued_responses_in_order_and_records_requests(): void
    {
        $client = (new FakeTargetHttpClient)
            ->respondWith(201, ['id' => 1])
            ->respondWith(400, ['error' => 'bad']);

        $first = $client->send(new TargetRequest('POST', 'http://benchmark/items', body: ['name' => 'a']));
        $second = $client->send(new TargetRequest('POST', 'http://benchmark/items'));
        $third = $client->send(new TargetRequest('GET', 'http://benchmark/items/9'));

        $this->assertSame(201, $first->status);
        $this->assertSame(['id' => 1], $first->json());
        $this->assertSame(400, $second->status);
        $this->assertSame(404, $third->status);
        $this->assertCount(3, $client->sent());
        $this->assertSame(['name' => 'a'], $client->sent()[0]->body);
    }
}
