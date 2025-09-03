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
final class DebtSimulationServiceAprConversionTest extends TestCase
{
    public function testConvertsAprToMonthlyRate(): void
    {
        Cache::flush();

        $service  = new DebtSimulationService();
        $userId   = 'user';
        $groupId  = 'group';
        $accounts = [
            ['account_id' => 1, 'balance' => 1200.0, 'apr' => 0.12, 'minimum_payment' => 0.0],
        ];

        $plans = $service->simulate($userId, $groupId, $accounts, 1200.0, 1);

        $firstMonthInterest = $plans[0]['schedule'][0]['interest'];
        self::assertEqualsWithDelta(12.0, $firstMonthInterest, 0.01);
    }
}
