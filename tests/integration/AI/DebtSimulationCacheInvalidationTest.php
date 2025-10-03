<?php

declare(strict_types=1);

namespace Tests\integration\AI;

use FireflyIII\Models\Account;
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
        $user                 = new User();
        $user->id             = 1;
        $user->user_group_id  = 1;

        $key = $this->seedCachedSimulation('1', '1');
        self::assertTrue(Cache::has($key));

        event('eloquent.deleted: ' . User::class, $user);

        self::assertFalse(Cache::has($key));
    }
}
