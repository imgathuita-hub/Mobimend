<?php

declare(strict_types=1);

use Mobimend\Core\HttpException;

function env(string $key, ?string $default = null): ?string
{
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function require_fields(array $payload, array $fields): void
{
    foreach ($fields as $field) {
        $value = $payload[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw new HttpException(sprintf('%s is required', $field), 400);
        }
    }
}

function string_value(mixed $value): string
{
    return trim((string) $value);
}

function int_value(mixed $value): int
{
    return (int) $value;
}

function float_value(mixed $value): float
{
    return (float) $value;
}
