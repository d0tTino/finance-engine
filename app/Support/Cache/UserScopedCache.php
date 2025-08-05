<?php

/*
 * UserScopedCache.php
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 */

declare(strict_types=1);

namespace FireflyIII\Support\Cache;

use Illuminate\Support\Facades\Cache;

/**
 * Simple helper around the cache store that namespaces keys by user and group.
 * It also keeps track of stored keys so that all cached values for a given
 * scope can be flushed when the underlying data changes.
 */
class UserScopedCache
{
    private static function prefix(string $userId, ?string $groupId): string
    {
        return sprintf('u:%s:g:%s', $userId, $groupId ?? 'null');
    }

    private static function indexKey(string $userId, ?string $groupId): string
    {
        return self::prefix($userId, $groupId) . ':keys';
    }

    public static function remember(string $userId, ?string $groupId, string $key, callable $callback, int $ttlSeconds = 3600)
    {
        $cacheKey = self::prefix($userId, $groupId) . ':' . $key;
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        $value = $callback();
        Cache::put($cacheKey, $value, $ttlSeconds);

        $indexKey = self::indexKey($userId, $groupId);
        $keys     = Cache::get($indexKey, []);
        if (!is_array($keys)) {
            $keys = [];
        }
        if (!in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;
            Cache::put($indexKey, $keys, $ttlSeconds);
        }

        return $value;
    }

    public static function forget(string $userId, ?string $groupId, string $key): void
    {
        $cacheKey = self::prefix($userId, $groupId) . ':' . $key;
        Cache::forget($cacheKey);
    }

    public static function flush(string $userId, ?string $groupId): void
    {
        $indexKey = self::indexKey($userId, $groupId);
        $keys     = Cache::pull($indexKey, []);
        foreach ($keys as $cacheKey) {
            Cache::forget($cacheKey);
        }
    }
}
