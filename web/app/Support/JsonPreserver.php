<?php

namespace App\Support;

use App\Models\Endpoint;
use stdClass;

class JsonPreserver
{
    /**
     * Preserve JSON types in decoded schema structures, leaving scalar strings untouched.
     */
    public static function preserveSchema(mixed $value, ?string $key = null): mixed
    {
        if ($value instanceof stdClass || is_object($value)) {
            $vars = get_object_vars((object) $value);
            if ($vars === []) {
                return (object) [];
            }
            $obj = new stdClass;
            foreach ($vars as $k => $v) {
                $obj->{$k} = self::preserveSchema($v, (string) $k);
            }

            return $obj;
        }

        if (is_array($value)) {
            if ($value === []) {
                if (in_array($key, ['enum', 'examples', 'required', 'tags', 'security', 'parameters'], true)) {
                    return [];
                }
                if (in_array($key, ['properties', 'additionalProperties', 'schema', 'content', 'responses'], true)) {
                    return (object) [];
                }

                return [];
            }

            if (array_is_list($value)) {
                return array_map(fn ($item) => self::preserveSchema($item, $key), $value);
            }

            $obj = new stdClass;
            foreach ($value as $k => $v) {
                if ($key === 'properties' && $v === []) {
                    $obj->{$k} = (object) [];
                } else {
                    $obj->{$k} = self::preserveSchema($v, (string) $k);
                }
            }

            return $obj;
        }

        return $value;
    }

    /**
     * Read request body from Endpoint preserving schema object shapes.
     */
    public static function preserveRequestBody(Endpoint $ep): ?object
    {
        $raw = $ep->getRawOriginal('request_body') ?? ($ep->getAttributes()['request_body'] ?? null);
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $preserved = self::preserveSchema(is_string($raw) ? json_decode($raw, false, 512, JSON_THROW_ON_ERROR) : $raw, 'request_body');
        if (is_array($preserved) && ! array_is_list($preserved)) {
            $preserved = (object) $preserved;
        }

        return is_object($preserved) ? $preserved : (object) $preserved;
    }

    /**
     * Read responses from Endpoint preserving schema object shapes.
     */
    public static function preserveResponses(Endpoint $ep): object
    {
        $raw = $ep->getRawOriginal('responses') ?? ($ep->getAttributes()['responses'] ?? null);
        if ($raw === null || $raw === '' || $raw === []) {
            return (object) [];
        }

        $preserved = self::preserveSchema(is_string($raw) ? json_decode($raw, false, 512, JSON_THROW_ON_ERROR) : $raw, 'responses');

        return is_object($preserved) ? $preserved : (object) $preserved;
    }

    /**
     * Read parameters from Endpoint preserving schema object shapes.
     *
     * @return list<mixed>|null
     */
    public static function preserveParameters(Endpoint $ep): ?array
    {
        $raw = $ep->getRawOriginal('parameters') ?? ($ep->getAttributes()['parameters'] ?? null);
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $preserved = self::preserveSchema(is_string($raw) ? json_decode($raw, false, 512, JSON_THROW_ON_ERROR) : $raw, 'parameters');
        if (is_array($preserved)) {
            return array_values($preserved);
        }

        return is_object($preserved) ? [$preserved] : null;
    }

    /**
     * Preserve decoded DSL payload types without interpreting scalar strings as JSON.
     */
    public static function preserveDslPayload(mixed $value): mixed
    {
        if ($value instanceof stdClass || is_object($value)) {
            $vars = get_object_vars((object) $value);
            if ($vars === []) {
                return (object) [];
            }

            $hasNumericKey = false;
            foreach (array_keys($vars) as $k) {
                if (is_numeric($k)) {
                    $hasNumericKey = true;
                    break;
                }
            }

            if ($hasNumericKey) {
                $obj = new stdClass;
                foreach ($vars as $k => $v) {
                    $obj->{$k} = self::preserveDslPayload($v);
                }

                return $obj;
            }

            $arr = [];
            foreach ($vars as $k => $v) {
                $arr[$k] = self::preserveDslPayload($v);
            }

            return $arr;
        }

        if (is_array($value)) {
            if ($value === []) {
                return [];
            }

            if (array_is_list($value)) {
                return array_map([self::class, 'preserveDslPayload'], $value);
            }

            $hasNumericKey = false;
            foreach (array_keys($value) as $k) {
                if (is_numeric($k)) {
                    $hasNumericKey = true;
                    break;
                }
            }

            if ($hasNumericKey) {
                $obj = new stdClass;
                foreach ($value as $k => $v) {
                    $obj->{$k} = self::preserveDslPayload($v);
                }

                return $obj;
            }

            $arr = [];
            foreach ($value as $k => $v) {
                $arr[$k] = self::preserveDslPayload($v);
            }

            return $arr;
        }

        return $value;
    }
}
