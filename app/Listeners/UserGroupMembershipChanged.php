<?php

declare(strict_types=1);

namespace FireflyIII\Listeners;

use FireflyIII\Models\GroupMembership;
use FireflyIII\Support\Cache\UserScopedCache;
use FireflyIII\User;

class UserGroupMembershipChanged
{
    public function handle(GroupMembership $membership): void
    {
        $currentUserId  = is_numeric($membership->user_id) ? (int) $membership->user_id : null;
        $currentGroupId = is_numeric($membership->user_group_id) ? (int) $membership->user_group_id : null;

        $this->flushFor($currentUserId, $currentGroupId);

        $originalUserId  = $membership->getOriginal('user_id');
        $originalGroupId = $membership->getOriginal('user_group_id');

        $originalUserId  = is_numeric($originalUserId) ? (int) $originalUserId : null;
        $originalGroupId = is_numeric($originalGroupId) ? (int) $originalGroupId : null;

        if ($originalUserId !== $currentUserId || $originalGroupId !== $currentGroupId) {
            $this->flushFor($originalUserId, $originalGroupId);
        }
    }

    private function flushFor(?int $userId, ?int $groupId): void
    {
        if (null === $userId) {
            return;
        }

        $userIdString = (string) $userId;

        // Always flush the default scope.
        UserScopedCache::flush($userIdString, null);

        if (null !== $groupId) {
            UserScopedCache::flush($userIdString, (string) $groupId);
        }

        $user = User::find($userId);
        if (null === $user) {
            return;
        }

        $groupIds = $user
            ->groupMemberships()
            ->pluck('user_group_id')
            ->filter(static fn ($membershipGroupId): bool => null !== $membershipGroupId)
            ->map(static fn ($membershipGroupId): string => (string) $membershipGroupId);

        if (null !== $user->user_group_id) {
            $groupIds->push((string) $user->user_group_id);
        }

        $groupIds
            ->unique()
            ->each(static function (string $membershipGroupId) use ($userIdString): void {
                UserScopedCache::flush($userIdString, $membershipGroupId);
            });
    }
}
