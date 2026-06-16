<?php

declare(strict_types=1);

namespace Mobimend\Services;

final class AnalyticsClient
{
    private string $baseUrl;
    private int $ttl;
    private ?\Redis $redis = null;
    private string $fileCacheDir;

    public function __construct(?string $baseUrl = null, int $ttl = 300)
    {
        $this->baseUrl = rtrim($baseUrl ?: (string) env('ANALYTICS_API_BASE', 'http://localhost:8001'), '/');
        $this->ttl = $ttl;
        $this->fileCacheDir = dirname(__DIR__, 3) . '/storage/cache/analytics';
        $this->redis = $this->connectRedis();
    }

    public function get(string $path, array $query = []): array
    {
        $path = '/' . ltrim($path, '/');
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $cacheKey = 'mobimend:analytics:' . sha1($url);
        $cached = $this->readCache($cacheKey);
        if (is_array($cached)) {
            return $cached + ['_cached' => true, '_available' => true];
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 2.5,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw) || $raw === '') {
            return ['_available' => false, '_cached' => false, '_error' => 'Analytics service unavailable.'];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['_available' => false, '_cached' => false, '_error' => 'Analytics service returned invalid JSON.'];
        }

        $this->writeCache($cacheKey, $decoded);
        return $decoded + ['_cached' => false, '_available' => true];
    }

    private function connectRedis(): ?\Redis
    {
        if (!class_exists(\Redis::class)) {
            return null;
        }

        try {
            $redis = new \Redis();
            $host = (string) env('REDIS_HOST', '127.0.0.1');
            $port = (int) env('REDIS_PORT', '6379');
            $timeout = (float) env('REDIS_TIMEOUT', '1.0');
            if (!$redis->connect($host, $port, $timeout)) {
                return null;
            }

            $password = (string) env('REDIS_PASSWORD', '');
            if ($password !== '') {
                $redis->auth($password);
            }

            $database = (int) env('REDIS_DATABASE', '0');
            if ($database > 0) {
                $redis->select($database);
            }

            return $redis;
        } catch (\Throwable) {
            return null;
        }
    }

    private function readCache(string $key): ?array
    {
        if ($this->redis instanceof \Redis) {
            $raw = $this->redis->get($key);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                return is_array($decoded) ? $decoded : null;
            }
        }

        $path = $this->fileCachePath($key);
        if (!is_file($path) || filemtime($path) + $this->ttl < time()) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeCache(string $key, array $payload): void
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        if ($this->redis instanceof \Redis) {
            $this->redis->setex($key, $this->ttl, $raw);
            return;
        }

        if (!is_dir($this->fileCacheDir)) {
            mkdir($this->fileCacheDir, 0775, true);
        }
        if (is_dir($this->fileCacheDir) && is_writable($this->fileCacheDir)) {
            file_put_contents($this->fileCachePath($key), $raw);
        }
    }

    private function fileCachePath(string $key): string
    {
        return $this->fileCacheDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.json';
    }
}
