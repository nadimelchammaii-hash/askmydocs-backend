<?php

namespace App\Services\OpenAi;

use InvalidArgumentException;

/**
 * A JSON Schema for Structured Outputs in strict mode, validated up front.
 *
 * Strict mode (OpenAI docs) requires, for every object: all properties listed
 * in `required` (optional values are expressed as a null union) and
 * `additionalProperties: false`; the root must be an object. Checking this
 * here turns a paid 400 error at runtime into an immediate failure in tests.
 */
final readonly class JsonSchema
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public string $name,
        public array $schema,
    ) {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name)) {
            throw new InvalidArgumentException('Schema name must be 1-64 letters, digits, "_" or "-".');
        }

        if (($schema['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException('The root of a strict schema must be an object.');
        }

        self::assertStrict($schema, '$');
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function assertStrict(array $node, string $path): void
    {
        $types = (array) ($node['type'] ?? []);

        if (in_array('object', $types, true)) {
            $properties = array_keys($node['properties'] ?? []);
            $required = $node['required'] ?? [];

            if (($node['additionalProperties'] ?? null) !== false) {
                throw new InvalidArgumentException("{$path}: objects must set additionalProperties to false.");
            }

            if (array_diff($properties, $required) !== [] || array_diff($required, $properties) !== []) {
                throw new InvalidArgumentException("{$path}: every property must be listed in required.");
            }

            foreach ($node['properties'] ?? [] as $property => $child) {
                self::assertStrict($child, "{$path}.{$property}");
            }
        }

        if (in_array('array', $types, true) && isset($node['items'])) {
            self::assertStrict($node['items'], "{$path}[]");
        }
    }
}
