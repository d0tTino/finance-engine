<?php

declare(strict_types=1);

namespace Tests\integration\AI;

use FireflyIII\Models\GroupMembership;
use FireflyIII\Support\Cache\UserScopedCache;
use Illuminate\Support\Facades\Cache;
use Tests\integration\TestCase;

/**
 * @group integration-test
 * @group ai
 *
 * @internal
 */
final class UserGroupMembershipCacheInvalidationTest extends TestCase
{
    public function testFlushesCacheWhenMembershipUpdated(): void
    {
        Cache::flush();

        UserScopedCache::remember('1', '1', 'debt-sim-test', fn() => 'cached', 3600);
        UserScopedCache::remember('1', '2', 'debt-sim-test', fn() => 'cached', 3600);

        $oldKey = 'u:1:g:1:debt-sim-test';
        $newKey = 'u:1:g:2:debt-sim-test';

        self::assertTrue(Cache::has($oldKey));
        self::assertTrue(Cache::has($newKey));

        $membership = new GroupMembership();
        $membership->setRawAttributes([
            'user_id'       => 1,
            'user_group_id' => 1,
        ], true);
        $membership->exists         = true;
        $membership->user_group_id  = 2;

        event('eloquent.updated: ' . GroupMembership::class, $membership);

        self::assertFalse(Cache::has($oldKey));
        self::assertFalse(Cache::has($newKey));
    }
}
