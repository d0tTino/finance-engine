<?php

declare(strict_types=1);

namespace FireflyIII\Listeners;

use FireflyIII\Models\Account;
use FireflyIII\Support\Cache\UserScopedCache;

/**
 * Flushes the user scoped cache for debt simulations when an account changes.
 */
class FlushDebtSimulationCache
{
    public function handle(Account $account): void
    {
        UserScopedCache::flush(
            (string) $account->user_id,
            $account->user_group_id === null ? null : (string) $account->user_group_id,
        );
    }
}
