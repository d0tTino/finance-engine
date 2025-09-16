<?php

declare(strict_types=1);

namespace FireflyIII\Listeners;

use FireflyIII\Models\GroupMembership;
use FireflyIII\Support\Cache\UserScopedCache;

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

        UserScopedCache::flush((string) $userId, null === $groupId ? null : (string) $groupId);
    }
}
