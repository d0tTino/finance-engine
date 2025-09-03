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
final class DebtSimulationNonConvergingTest extends TestCase
{
    public function testDetectsGrowingBalance(): void
    {
        Cache::flush();
        $service = new DebtSimulationService();

        $accounts = [
            ['account_id' => 1, 'balance' => 1000.0, 'apr' => 1.20, 'min_payment' => 0.0],
        ];
        $budget = 50.0;

        $plans = $service->simulate('user', 'group', $accounts, $budget, 1);

        self::assertSame('non_converging', $plans[0]['status']);
        self::assertFalse($plans[0]['is_optimal']);
        self::assertLessThanOrEqual(DebtSimulationService::MAX_MONTHS, $plans[0]['months']);
    }
}
