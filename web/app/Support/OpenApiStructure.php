<?php

namespace App\Support;

/**
 * Normalize OpenAPI structural maps to PHP arrays without erasing JSON literal types.
 *
 * Associative JSON decoding cannot distinguish `{}` from `[]`, or an object with
 * consecutive numeric keys from a JSON array. Values carried by JSON Schema's
 * const and enum keywords therefore retain their source JSON types.
 */
final class OpenApiStructure
{
    private const CONTEXT_STRUCTURE = 'structure';

    private const CONTEXT_SCHEMA = 'schema';

    private const CONTEXT_SCHEMA_MAP = 'schema_map';

    private const CONTEXT_SCHEMA_LIST = 'schema_list';

    public static function decodeJson(string $json): mixed
    {
        return self::normalize(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    public static function normalize(mixed $value): mixed
    {
        return self::normalizeValue($value, self::CONTEXT_STRUCTURE);
    }

    private static function normalizeValue(mixed $value, string $context): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $keyName = (string) $key;
            if ($context === self::CONTEXT_SCHEMA && $keyName === 'const') {
                $normalized[$key] = self::preserveLiteral($item);

                continue;
            }

            if ($context === self::CONTEXT_SCHEMA && $keyName === 'enum' && is_array($item)) {
                $normalized[$key] = array_map(
                    static fn (mixed $literal): mixed => self::preserveLiteral($literal),
                    $item,
                );

                continue;
            }

            $childContext = match ($context) {
                self::CONTEXT_SCHEMA_MAP, self::CONTEXT_SCHEMA_LIST => self::CONTEXT_SCHEMA,
                self::CONTEXT_SCHEMA => self::schemaChildContext($keyName),
                default => $keyName === 'schema'
                    ? self::CONTEXT_SCHEMA
                    : ($keyName === 'schemas' ? self::CONTEXT_SCHEMA_MAP : self::CONTEXT_STRUCTURE),
            };
            $normalized[$key] = self::normalizeValue($item, $childContext);
        }

        return $normalized;
    }

    private static function schemaChildContext(string $key): string
    {
        if (in_array($key, ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'], true)) {
            return self::CONTEXT_SCHEMA_MAP;
        }

        if (in_array($key, ['allOf', 'anyOf', 'oneOf', 'prefixItems'], true)) {
            return self::CONTEXT_SCHEMA_LIST;
        }

        if (in_array($key, [
            'additionalProperties', 'additionalItems', 'items', 'not', 'if', 'then', 'else',
            'contains', 'propertyNames', 'unevaluatedProperties', 'unevaluatedItems', 'contentSchema',
        ], true)) {
            return self::CONTEXT_SCHEMA;
        }

        return self::CONTEXT_STRUCTURE;
    }

    private static function preserveLiteral(mixed $value): mixed
    {
        if (is_object($value)) {
            $literal = new \stdClass;
            foreach (get_object_vars($value) as $key => $item) {
                $literal->{$key} = self::preserveLiteral($item);
            }

            return $literal;
        }

        if (is_array($value)) {
            $literal = [];
            foreach ($value as $key => $item) {
                $literal[$key] = self::preserveLiteral($item);
            }

            return $literal;
        }

        return $value;
    }
}
