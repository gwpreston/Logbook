<?php

declare(strict_types=1);

namespace Logbook\Support\Json;

/**
 * Checks a decoded JSON value against the subset of JSON Schema that
 * Logbook's own schemas use (spec.md §5 *AI adapters*): `type` (one or a
 * list), `properties`, `required`, `additionalProperties: false`, `items`,
 * `enum`, `minimum` / `maximum` and `minLength` / `maxLength`. Unknown
 * keywords are ignored. Every structured model answer passes through it.
 */
final class SchemaCheck
{
    /**
     * The problems found, each with its JSON path; empty when it fits.
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    public static function errors(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];

        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $matches = false;
            foreach ($types as $type) {
                if (is_string($type) && self::isType($value, $type)) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                return [sprintf('%s: expected %s', $path, implode(' or ', array_filter($types, is_string(...))))];
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = sprintf('%s: not one of the allowed values', $path);
        }

        if (is_int($value) || is_float($value)) {
            if (is_numeric($schema['minimum'] ?? null) && $value < $schema['minimum']) {
                $errors[] = sprintf('%s: below %s', $path, (string) $schema['minimum']);
            }
            if (is_numeric($schema['maximum'] ?? null) && $value > $schema['maximum']) {
                $errors[] = sprintf('%s: above %s', $path, (string) $schema['maximum']);
            }
        }

        if (is_string($value)) {
            if (is_int($schema['minLength'] ?? null) && mb_strlen($value) < $schema['minLength']) {
                $errors[] = sprintf('%s: shorter than %d', $path, $schema['minLength']);
            }
            if (is_int($schema['maxLength'] ?? null) && mb_strlen($value) > $schema['maxLength']) {
                $errors[] = sprintf('%s: longer than %d', $path, $schema['maxLength']);
            }
        }

        if (is_array($value) && array_is_list($value) && is_array($schema['items'] ?? null)) {
            foreach ($value as $i => $item) {
                $errors = [...$errors, ...self::errors($item, self::schema($schema['items']), $path . '[' . $i . ']')];
            }
        }

        if (is_array($value) && (!array_is_list($value) || $value === []) && self::describesObject($schema)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $name) {
                if (is_string($name) && !array_key_exists($name, $value)) {
                    $errors[] = sprintf('%s.%s: required', $path, $name);
                }
            }
            foreach ($value as $name => $item) {
                $name = (string) $name;
                if (is_array($properties[$name] ?? null)) {
                    $errors = [...$errors, ...self::errors($item, self::schema($properties[$name]), $path . '.' . $name)];
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = sprintf('%s.%s: not allowed', $path, $name);
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $schema
     */
    public static function fits(mixed $value, array $schema): bool
    {
        return self::errors($value, $schema) === [];
    }

    private static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && (!array_is_list($value) || $value === []),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function describesObject(array $schema): bool
    {
        $type = $schema['type'] ?? null;

        return isset($schema['properties']) || isset($schema['required'])
            || $type === 'object' || (is_array($type) && in_array('object', $type, true));
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $schema = [];
        foreach ($value as $key => $item) {
            $schema[(string) $key] = $item;
        }

        return $schema;
    }
}
