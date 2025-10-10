<?php

declare(strict_types=1);

namespace FireflyIII\Listeners;

use FireflyIII\Models\Account;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Support\Cache\UserScopedCache;
use FireflyIII\User;
use Illuminate\Support\Collection;

/**
 * Flushes the user scoped cache for debt simulations when an account or user changes.
 */
class FlushDebtSimulationCache
{
    public function handle(Account|User $model): void
    {
        if ($model instanceof Account) {
            $userId  = (string) $model->user_id;
            $groupId = $model->user_group_id === null ? null : (string) $model->user_group_id;

            UserScopedCache::flush($userId, $groupId);

            return;
        }

        $userId = (string) $model->id;

        // Always flush the default scope.
        UserScopedCache::flush($userId, null);

        $groupIds = $this->getMemberships($model)
            ->pluck('user_group_id')
            ->filter(static fn ($groupId): bool => null !== $groupId)
            ->map(static fn ($groupId): string => (string) $groupId);

        if (null !== $model->user_group_id) {
            $groupIds->push((string) $model->user_group_id);
        }

        $groupIds
            ->unique()
            ->each(static function (string $groupId) use ($userId): void {
                UserScopedCache::flush($userId, $groupId);
            });
    }

    /**
     * @return Collection<int, GroupMembership>
     */
    private function getMemberships(User $user): Collection
    {
        if ($user->relationLoaded('groupMemberships')) {
            return $user->groupMemberships;
        }

        if (!$user->exists) {
            return collect();
        }

        return $user->groupMemberships()->get();
    }
}
