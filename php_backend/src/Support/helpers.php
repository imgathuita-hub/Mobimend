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

function slugify(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'item';
}

function order_number(string $prefix = 'MM'): string
{
    return $prefix . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function redirect_with_message(string $path, string $message, string $tone = 'success'): never
{
    header('Location: ' . $path . '?message=' . urlencode($message) . '&tone=' . urlencode($tone));
    exit;
}
