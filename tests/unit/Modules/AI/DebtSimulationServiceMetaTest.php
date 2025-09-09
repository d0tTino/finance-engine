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
final class DebtSimulationServiceMetaTest extends TestCase
{
    public function testPlanMetaIncludesRequiredFields(): void
    {
        Cache::flush();

        $service = new DebtSimulationService();
        $user    = $this->createAuthenticatedUser();

        $accounts = [
            ['account_id' => 1, 'name' => 'Loan1', 'balance' => 1000.0, 'apr' => 0.10, 'min_payment' => 0.0],
            ['account_id' => 2, 'name' => 'Loan2', 'balance' => 500.0,  'apr' => 0.05, 'min_payment' => 0.0],
        ];

        $plans = $service->simulate((string) $user->id, '1', $accounts, 300.0, 2);

        foreach ($plans as $plan) {
            self::assertArrayHasKey('ranking_heuristic', $plan['meta']);
            self::assertArrayHasKey('ranking_reason', $plan['meta']);
            self::assertArrayHasKey('tradeoff_drivers', $plan['meta']);
            self::assertArrayHasKey('strategy_explanation', $plan['meta']);

            self::assertIsArray($plan['meta']['tradeoff_drivers']);
            self::assertArrayHasKey('currency', $plan['meta']['tradeoff_drivers']);
            self::assertArrayHasKey('time_months', $plan['meta']['tradeoff_drivers']);
            self::assertIsString($plan['meta']['strategy_explanation']);
        }
    }
}

