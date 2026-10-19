<?php

namespace Tests\Unit\Rules;

use App\Rules\HostOrIp;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * S14-06: the M04 host error must not repeat the field label
 * ("Trường host hoặc địa chỉ IP phải là hostname hoặc địa chỉ IP hợp lệ…").
 */
class HostOrIpTest extends TestCase
{
    /**
     * @return array<string>
     */
    private function errors(mixed $value): array
    {
        $validator = Validator::make(['host' => $value], ['host' => [new HostOrIp]], [], ['host' => 'host hoặc địa chỉ IP']);

        return $validator->errors()->messages()['host'] ?? [];
    }

    public function test_bare_hostnames_and_ips_pass(): void
    {
        foreach (['api.example.com', 'simple-store', '10.0.0.5', '2001:db8::1'] as $host) {
            $this->assertSame([], $this->errors($host), $host);
        }
    }

    public function test_url_is_rejected_with_a_message_that_does_not_repeat_the_label(): void
    {
        foreach (['http://qa-admin.example.com/x', 'api.example.com:8080', 'api.example.com/v1'] as $host) {
            $this->assertSame([HostOrIp::MESSAGE_INVALID], $this->errors($host), $host);
        }
        $this->assertStringNotContainsString('host hoặc địa chỉ IP', HostOrIp::MESSAGE_INVALID);
        $this->assertStringNotContainsString('Trường', HostOrIp::MESSAGE_INVALID);
    }

    public function test_non_string_value_gets_its_own_message(): void
    {
        $this->assertSame([HostOrIp::MESSAGE_NOT_STRING], $this->errors(['a.example.com']));
        $this->assertStringNotContainsString('Trường', HostOrIp::MESSAGE_NOT_STRING);
    }
}
