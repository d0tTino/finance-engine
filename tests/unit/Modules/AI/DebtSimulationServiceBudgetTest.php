<?php

declare(strict_types=1);

namespace Tests\unit\Modules\AI;

use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
use Illuminate\Support\Facades\Cache;
use Tests\integration\TestCase;

/**
 * @group unit-test
 * @group ai
 */
final class DebtSimulationServiceBudgetTest extends TestCase
{
    public function testClampsNegativeCashFlow(): void
    {
        Cache::flush();

        $service  = new DebtSimulationService();
        $userId   = 'user';
        $groupId  = 'group';
        $accounts = [
            ['account_id' => 1, 'balance' => 100.0, 'apr' => 0.0, 'min_payment' => 60.0],
            ['account_id' => 2, 'balance' => 100.0, 'apr' => 0.0, 'min_payment' => 60.0],
        ];

        $plans = $service->simulate($userId, $groupId, $accounts, 50.0, 1);

        foreach ($plans[0]['schedule'] as $month) {
            self::assertGreaterThanOrEqual(0.0, $month['cash_flow']);
        }
    }

    public function testReturnsEmptyWhenNoStrategies(): void
    {
        Cache::flush();

        $service  = new DebtSimulationService([]);
        $userId   = 'user';
        $groupId  = 'group';
        $accounts = [
            ['account_id' => 1, 'balance' => 100.0, 'apr' => 5.0],
        ];

        $plans = $service->simulate($userId, $groupId, $accounts, 50.0, 1);

        self::assertSame([], $plans);
    }
}
