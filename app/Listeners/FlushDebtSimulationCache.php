<?php

declare(strict_types=1);

namespace FireflyIII\Listeners;

use FireflyIII\Models\Account;
use FireflyIII\Support\Cache\UserScopedCache;
use FireflyIII\User;

/**
 * Flushes the user scoped cache for debt simulations when an account or user changes.
 */
class FlushDebtSimulationCache
{
    public function handle(Account|User $model): void
    {
        $userId  = $model instanceof Account ? (string) $model->user_id : (string) $model->id;
        $groupId = $model->user_group_id === null ? null : (string) $model->user_group_id;

        UserScopedCache::flush($userId, $groupId);
    }
}
