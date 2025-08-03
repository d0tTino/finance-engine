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
    private static function prefix(int $userId, int $groupId): string
    {
        return sprintf('u:%d:g:%d', $userId, $groupId);
    }

    private static function indexKey(int $userId, int $groupId): string
    {
        return self::prefix($userId, $groupId) . ':keys';
    }

    public static function remember(int $userId, int $groupId, string $key, callable $callback, int $ttlSeconds = 3600)
    {
        $cacheKey = self::prefix($userId, $groupId) . ':' . $key;
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        $value = $callback();
        Cache::put($cacheKey, $value, $ttlSeconds);

        $indexKey = self::indexKey($userId, $groupId);
        $keys     = Cache::get($indexKey, []);
        if (!in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;
            Cache::put($indexKey, $keys, $ttlSeconds);
        }

        return $value;
    }

    public static function forget(int $userId, int $groupId, string $key): void
    {
        $cacheKey = self::prefix($userId, $groupId) . ':' . $key;
        Cache::forget($cacheKey);
    }

    public static function flush(int $userId, int $groupId): void
    {
        $indexKey = self::indexKey($userId, $groupId);
        $keys     = Cache::pull($indexKey, []);
        foreach ($keys as $cacheKey) {
            Cache::forget($cacheKey);
        }
    }
}
