<?php

declare(strict_types=1);

namespace EidCloud\ApiMock\Generator;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Realistic synthetic payload generator matching JSON Schemas, formats, types, and constraints.
 */
class PayloadFaker
{
    private static int $seed = 1337;
    private static int $idCounter = 1000;

    /**
     * Resets sequence counter or seed for reproducible test results.
     */
    public static function reset(int $seed = 1337): void
    {
        self::$seed = $seed;
        self::$idCounter = 1000;
        mt_srand($seed);
    }

    /**
     * Generates synthetic mock data compliant with the provided JSON Schema.
     */
    public static function generate(array $schema, array $context = []): mixed
    {
        // 1. Direct examples
        if (isset($schema['example'])) {
            return $schema['example'];
        }
        if (isset($schema['default'])) {
            return $schema['default'];
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !empty($schema['enum'])) {
            return $schema['enum'][array_rand($schema['enum'])];
        }

        // 2. Composition (allOf, oneOf, anyOf)
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $merged = [];
            foreach ($schema['allOf'] as $subSchema) {
                $subRes = self::generate($subSchema, $context);
                if (is_array($subRes)) {
                    $merged = array_merge($merged, $subRes);
                }
            }
            return $merged;
        }
        if (isset($schema['oneOf']) && is_array($schema['oneOf']) && !empty($schema['oneOf'])) {
            return self::generate($schema['oneOf'][array_rand($schema['oneOf'])], $context);
        }
        if (isset($schema['anyOf']) && is_array($schema['anyOf']) && !empty($schema['anyOf'])) {
            return self::generate($schema['anyOf'][array_rand($schema['anyOf'])], $context);
        }

        $type = $schema['type'] ?? 'object';
        if (is_array($type)) {
            $type = $type[0]; // If union e.g. ["string", "null"]
        }

        $format = $schema['format'] ?? null;
        $propertyName = $context['propertyName'] ?? '';

        return match ($type) {
            'string' => self::generateString($schema, $propertyName, $format),
            'integer' => self::generateInteger($schema, $propertyName),
            'number' => self::generateNumber($schema, $propertyName),
            'boolean' => self::generateBoolean($propertyName),
            'array' => self::generateArray($schema, $context),
            'object' => self::generateObject($schema, $context),
            'null' => null,
            default => self::generateObject($schema, $context),
        };
    }

    private static function generateString(array $schema, string $name, ?string $format): string
    {
        $nameLower = strtolower($name);

        if ($format === 'uuid' || str_contains($nameLower, 'uuid')) {
            return sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
        }

        if ($format === 'email' || str_contains($nameLower, 'email')) {
            $firstNames = ['alex', 'jordan', 'taylor', 'morgan', 'shadi', 'sam', 'casey', 'riley'];
            $domains = ['eidcloud.io', 'example.com', 'api-mock.dev', 'cloud.net'];
            return $firstNames[array_rand($firstNames)] . mt_rand(10, 99) . '@' . $domains[array_rand($domains)];
        }

        if ($format === 'uri' || $format === 'url' || str_contains($nameLower, 'url') || str_contains($nameLower, 'link')) {
            $hosts = ['api.eidcloud.io', 'cdn.eidcloud.io', 'assets.example.com'];
            $paths = ['v1/resources', 'media/avatars/user.png', 'docs/overview'];
            return 'https://' . $hosts[array_rand($hosts)] . '/' . $paths[array_rand($paths)];
        }

        if ($format === 'date-time' || str_contains($nameLower, '_at') || str_contains($nameLower, 'timestamp')) {
            $dt = new DateTimeImmutable('-' . mt_rand(1, 30) . ' days');
            return $dt->format(DateTimeInterface::RFC3339);
        }

        if ($format === 'date' || str_contains($nameLower, 'date')) {
            return (new DateTimeImmutable())->format('Y-m-d');
        }

        if ($format === 'ipv4' || str_contains($nameLower, 'ip')) {
            return mt_rand(10, 200) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
        }

        // Semantic heuristics based on property name
        if (str_contains($nameLower, 'name')) {
            if (str_contains($nameLower, 'first')) return ['Aiden', 'Elena', 'Lucas', 'Maya', 'Nour', 'Omar'][mt_rand(0, 5)];
            if (str_contains($nameLower, 'last')) return ['Smith', 'Al-Hasan', 'Chen', 'Vance', 'Garcia', 'Kowalski'][mt_rand(0, 5)];
            return ['John Doe', 'Shadi AL-Hasan', 'Sarah Connor', 'Marcus Brody', 'Amira Vance'][mt_rand(0, 4)];
        }

        if (str_contains($nameLower, 'phone')) {
            return '+1-555-' . sprintf('%03d-%04d', mt_rand(100, 999), mt_rand(1000, 9999));
        }

        if (str_contains($nameLower, 'city')) {
            return ['San Francisco', 'Berlin', 'Dubai', 'Tokyo', 'London', 'Toronto'][mt_rand(0, 5)];
        }

        if (str_contains($nameLower, 'country')) {
            return ['United States', 'Germany', 'United Arab Emirates', 'Japan', 'Canada'][mt_rand(0, 4)];
        }

        if (str_contains($nameLower, 'status')) {
            return ['active', 'pending', 'verified', 'completed'][mt_rand(0, 3)];
        }

        if (str_contains($nameLower, 'role')) {
            return ['admin', 'developer', 'viewer', 'billing_manager'][mt_rand(0, 3)];
        }

        if (str_contains($nameLower, 'token') || str_contains($nameLower, 'key') || str_contains($nameLower, 'secret')) {
            return 'eid_' . bin2hex(random_bytes(16));
        }

        if (isset($schema['pattern'])) {
            return self::generateFromPattern($schema['pattern']);
        }

        $minLen = $schema['minLength'] ?? 4;
        $maxLen = $schema['maxLength'] ?? 16;
        $words = ['lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'cloud', 'service', 'mock'];
        $val = ucfirst($words[array_rand($words)]) . ' ' . $words[array_rand($words)];

        if (strlen($val) < $minLen) {
            $val = str_pad($val, $minLen, 'x');
        } elseif (strlen($val) > $maxLen) {
            $val = substr($val, 0, $maxLen);
        }

        return $val;
    }

    private static function generateInteger(array $schema, string $name): int
    {
        $nameLower = strtolower($name);
        if ($nameLower === 'id' || str_ends_with($nameLower, '_id')) {
            return ++self::$idCounter;
        }

        $min = $schema['minimum'] ?? 1;
        $max = $schema['maximum'] ?? 1000;

        if ($max < $min) {
            $max = $min + 100;
        }

        return mt_rand((int) $min, (int) $max);
    }

    private static function generateNumber(array $schema, string $name): float
    {
        $min = (float) ($schema['minimum'] ?? 10.0);
        $max = (float) ($schema['maximum'] ?? 1000.0);

        if ($max < $min) {
            $max = $min + 50.0;
        }

        $randFloat = $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
        return round($randFloat, 2);
    }

    private static function generateBoolean(string $name): bool
    {
        $nameLower = strtolower($name);
        if (str_starts_with($nameLower, 'is_') || str_starts_with($nameLower, 'has_')) {
            return true;
        }
        return mt_rand(0, 1) === 1;
    }

    private static function generateArray(array $schema, array $context): array
    {
        $itemsSchema = $schema['items'] ?? ['type' => 'string'];
        $minItems = $schema['minItems'] ?? 1;
        $maxItems = $schema['maxItems'] ?? 3;
        $count = mt_rand(max(1, $minItems), max(1, $maxItems));

        $list = [];
        for ($i = 0; $i < $count; $i++) {
            $list[] = self::generate($itemsSchema, $context);
        }
        return $list;
    }

    private static function generateObject(array $schema, array $context): array
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? array_keys($properties);

        $obj = [];

        // Ensure all defined properties or required properties are generated
        foreach ($properties as $propName => $propSchema) {
            $obj[$propName] = self::generate($propSchema, ['propertyName' => $propName]);
        }

        // If no properties defined, check additionalProperties
        if (empty($properties)) {
            if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
                $obj['item1'] = self::generate($schema['additionalProperties'], ['propertyName' => 'item1']);
                $obj['item2'] = self::generate($schema['additionalProperties'], ['propertyName' => 'item2']);
            } else {
                $obj['id'] = ++self::$idCounter;
                $obj['status'] = 'success';
                $obj['message'] = 'Resource generated successfully';
            }
        }

        return $obj;
    }

    private static function generateFromPattern(string $pattern): string
    {
        // Simple synthetic pattern matches
        if (str_contains($pattern, '[0-9]')) {
            return (string) mt_rand(1000, 9999);
        }
        if (str_contains($pattern, '[A-Z]')) {
            return 'EID-' . mt_rand(100, 999);
        }
        return 'PATTERN-MOCK-' . mt_rand(10, 99);
    }
}
