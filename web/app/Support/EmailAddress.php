<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Canonical form of a login email (QA S13-03): trimmed and lower-cased, as stored in users.email and
 * enforced by the unique index on lower(email). Accounts are therefore unique and login works
 * whatever letter case the person types.
 */
final class EmailAddress
{
    /**
     * Lower-case and trim a string; any other value is returned unchanged so the validator can
     * reject it (array, null...).
     */
    public static function normalize(mixed $value): mixed
    {
        return is_string($value) ? Str::lower(trim($value)) : $value;
    }
}
