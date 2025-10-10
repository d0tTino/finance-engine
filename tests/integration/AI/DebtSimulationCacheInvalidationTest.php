<?php

declare(strict_types=1);

namespace Tests\integration\AI;

use FireflyIII\Models\Account;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
use FireflyIII\User;
use Illuminate\Support\Facades\Cache;
use Tests\integration\TestCase;

/**
 * @group integration-test
 * @group ai
 *
 * @internal
 */
final class DebtSimulationCacheInvalidationTest extends TestCase
{
    private DebtSimulationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->service = new DebtSimulationService();
    }

    private function seedCachedSimulation(string $userId, ?string $groupId): string
    {
        $accounts = [
            ['account_id' => 1, 'balance' => 100.0, 'apr' => 5.0],
            ['account_id' => 2, 'balance' => 250.0, 'apr' => 12.0],
        ];
        $budget     = 75.0;
        $maxOptions = 2;

        $this->service->simulate($userId, $groupId, $accounts, $budget, $maxOptions);

        usort($accounts, static function (array $a, array $b): int {
            return ($a['account_id'] ?? 0) <=> ($b['account_id'] ?? 0);
        });

        $heuristic = (string) config('ai.ranking_heuristic', DebtSimulationService::RANKING_HEURISTIC);
        $strategies = array_map(
            static fn (string $strategyClass): string => get_class(app($strategyClass)),
            config('ai.debt_simulation_strategies', [])
        );

        $hash = hash('sha256', serialize([$accounts, $budget, $maxOptions, $heuristic, $strategies]));

        return sprintf('u:%s:g:%s:debt-sim-%s', $userId, $groupId ?? 'null', $hash);
    }

    public function testFlushesCacheOnAccountUpdated(): void
    {
        $account = new Account(['user_id' => 1, 'user_group_id' => 1]);
        $key     = $this->seedCachedSimulation('1', '1');
        self::assertTrue(Cache::has($key));

        event('eloquent.updated: ' . Account::class, $account);

        self::assertFalse(Cache::has($key));
    }

    public function testFlushesCacheOnAccountDeleted(): void
    {
        $account = new Account(['user_id' => 1, 'user_group_id' => 1]);
        $key     = $this->seedCachedSimulation('1', '1');
        self::assertTrue(Cache::has($key));

        event('eloquent.deleted: ' . Account::class, $account);

        self::assertFalse(Cache::has($key));
    }

    public function testFlushesCacheOnUserDeleted(): void
    {
        $user      = $this->createUserWithGroups();
        $memberships = $user->groupMemberships()->get();
        $groupIds = $memberships->pluck('user_group_id')->map(static fn ($id): string => (string) $id)->all();

        $defaultKey = $this->seedCachedSimulation((string) $user->id, null);
        $groupKeys  = array_map(fn (string $groupId): string => $this->seedCachedSimulation((string) $user->id, $groupId), $groupIds);

        self::assertTrue(Cache::has($defaultKey));
        foreach ($groupKeys as $key) {
            self::assertTrue(Cache::has($key));
        }

        $user->load('groupMemberships');
        event('eloquent.deleted: ' . User::class, $user);

        self::assertFalse(Cache::has($defaultKey));
        foreach ($groupKeys as $key) {
            self::assertFalse(Cache::has($key));
        }
    }

    public function testFlushesPrimaryGroupCacheOnUserDeletedWhenMembershipMissing(): void
    {
        $user             = $this->createUserWithGroups(includePrimaryMembership: false);
        $memberships      = $user->groupMemberships()->get();
        $primaryGroupId   = (string) $user->user_group_id;
        $secondaryGroupId = $memberships
            ->pluck('user_group_id')
            ->reject(static fn ($groupId): bool => (int) $groupId === (int) $user->user_group_id)
            ->map(static fn ($groupId): string => (string) $groupId)
            ->first();

        self::assertNotNull($secondaryGroupId);

        $defaultKey   = $this->seedCachedSimulation((string) $user->id, null);
        $primaryKey   = $this->seedCachedSimulation((string) $user->id, $primaryGroupId);
        $secondaryKey = $this->seedCachedSimulation((string) $user->id, $secondaryGroupId);

        self::assertTrue(Cache::has($defaultKey));
        self::assertTrue(Cache::has($primaryKey));
        self::assertTrue(Cache::has($secondaryKey));

        $user->load('groupMemberships');
        event('eloquent.deleted: ' . User::class, $user);

        self::assertFalse(Cache::has($defaultKey));
        self::assertFalse(Cache::has($primaryKey));
        self::assertFalse(Cache::has($secondaryKey));
    }

    public function testFlushesCacheForAllGroupsOnMembershipUpdated(): void
    {
        $user         = $this->createUserWithGroups();
        $memberships  = $user->groupMemberships()->get();
        $groupIds     = $memberships->pluck('user_group_id')->map(static fn ($id): string => (string) $id)->all();
        $defaultKey   = $this->seedCachedSimulation((string) $user->id, null);
        $groupKeys    = array_map(fn (string $groupId): string => $this->seedCachedSimulation((string) $user->id, $groupId), $groupIds);

        self::assertTrue(Cache::has($defaultKey));
        foreach ($groupKeys as $key) {
            self::assertTrue(Cache::has($key));
        }

        $membership = $memberships->first();
        $newRoleId  = UserRole::where('title', UserRoleEnum::MANAGE_TRANSACTIONS)->firstOrFail()->id;
        $membership->update(['user_role_id' => $newRoleId]);

        self::assertFalse(Cache::has($defaultKey));
        foreach ($groupKeys as $key) {
            self::assertFalse(Cache::has($key));
        }
    }

    public function testFlushesCacheForAllGroupsOnMembershipDeleted(): void
    {
        $user         = $this->createUserWithGroups();
        $memberships  = $user->groupMemberships()->get();
        $groupIds     = $memberships->pluck('user_group_id')->map(static fn ($id): string => (string) $id)->all();
        $defaultKey   = $this->seedCachedSimulation((string) $user->id, null);
        $groupKeys    = array_map(fn (string $groupId): string => $this->seedCachedSimulation((string) $user->id, $groupId), $groupIds);

        self::assertTrue(Cache::has($defaultKey));
        foreach ($groupKeys as $key) {
            self::assertTrue(Cache::has($key));
        }

        $memberships->first()->delete();

        self::assertFalse(Cache::has($defaultKey));
        foreach ($groupKeys as $key) {
            self::assertFalse(Cache::has($key));
        }
    }

    private function createUserWithGroups(bool $includePrimaryMembership = true): User
    {
        $primaryGroup = UserGroup::create(['title' => 'Primary Group']);
        $secondary    = UserGroup::create(['title' => 'Secondary Group']);

        $user = User::create([
            'email'         => 'user-' . uniqid('', true) . '@example.com',
            'password'      => bcrypt('secret'),
            'user_group_id' => $primaryGroup->id,
        ]);

        $roleId = UserRole::where('title', UserRoleEnum::FULL)->firstOrFail()->id;

        if ($includePrimaryMembership) {
            GroupMembership::create([
                'user_id'       => $user->id,
                'user_group_id' => $primaryGroup->id,
                'user_role_id'  => $roleId,
            ]);
        } else {
            GroupMembership::where('user_id', $user->id)
                ->where('user_group_id', $primaryGroup->id)
                ->delete();

            $user->forceFill(['user_group_id' => $primaryGroup->id])->save();
            $user->refresh();
        }

        GroupMembership::create([
            'user_id'       => $user->id,
            'user_group_id' => $secondary->id,
            'user_role_id'  => $roleId,
        ]);

        return $user;
    }
}
