<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class HostOrIp implements ValidationRule
{
    /**
     * Shown when the value is not a bare hostname or IP. It does not use :attribute: the field label
     * ("host hoặc địa chỉ IP") would repeat the wording of the message (S14-06).
     */
    public const MESSAGE_INVALID = 'Chỉ nhập hostname hoặc địa chỉ IP (ví dụ api.example.com hoặc 10.0.0.5), không kèm giao thức (http://), cổng hay đường dẫn.';

    public const MESSAGE_NOT_STRING = 'Host phải là một chuỗi: hostname hoặc địa chỉ IP.';

    /**
     * Validate that a value is a bare hostname or IP address, without a URL scheme or path.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(self::MESSAGE_NOT_STRING);

            return;
        }

        $isIpAddress = filter_var($value, FILTER_VALIDATE_IP) !== false;
        $isHostname = filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        if (! $isIpAddress && ! $isHostname) {
            $fail(self::MESSAGE_INVALID);
        }
    }
}
