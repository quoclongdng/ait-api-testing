<?php

namespace App\Support;

/**
 * Effective OpenAPI security of an operation (operation-level, or inherited global, as stored by
 * the parser in endpoints.security).
 */
final class OpenApiSecurity
{
    /**
     * A credential is required when the security lists at least one requirement and none of them
     * is the empty requirement `{}` (which makes authentication optional).
     */
    public static function requiresAuth(mixed $security): bool
    {
        if (is_object($security)) {
            $security = get_object_vars($security);
        }
        if (! is_array($security) || $security === []) {
            return false;
        }

        foreach ($security as $requirement) {
            if (is_object($requirement)) {
                $requirement = get_object_vars($requirement);
            }
            if (! is_array($requirement) || $requirement === []) {
                return false;
            }
        }

        return true;
    }
}
