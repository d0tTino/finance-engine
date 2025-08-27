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
final class MlStrategyTest extends TestCase
{
    public function testStrategyIsInvokedAndRankedWithAnnotations(): void
    {
        Cache::flush();

        $service = new DebtSimulationService();
        $user    = $this->createAuthenticatedUser();

        $accounts = [
            ['account_id' => 1, 'name' => 'Loan1', 'balance' => 1000.0, 'apr' => 10.0, 'min_payment' => 0.0],
            ['account_id' => 2, 'name' => 'Loan2', 'balance' => 500.0, 'apr' => 5.0, 'min_payment' => 0.0],
        ];

        $plans = $service->simulate((string) $user->id, '1', $accounts, 300.0, 4);

        self::assertCount(4, $plans);

        $strategies = array_column($plans, 'strategy');
        self::assertContains('ml', $strategies);
        $mlIndex = array_search('ml', $strategies, true);
        self::assertNotFalse($mlIndex);
        $mlPlan = $plans[$mlIndex];

        // ensure ml strategy ranked last and is marked non-converging
        self::assertSame($mlIndex + 1, $mlPlan['rank']);
        self::assertSame('non_converging', $mlPlan['status']);

        // cost-of-deviation relative to best plan
        $bestPlan         = $plans[0];
        $expectedCurrency = $mlPlan['total_interest'] - $bestPlan['total_interest'];
        $expectedMonths   = $mlPlan['months'] - $bestPlan['months'];
        self::assertEqualsWithDelta($expectedCurrency, $mlPlan['cost_of_deviation']['currency'], 0.0001);
        self::assertSame($expectedMonths, $mlPlan['cost_of_deviation']['time_months']);

        // meta field validation
        self::assertSame(DebtSimulationService::RANKING_HEURISTIC, $mlPlan['meta']['ranking_heuristic']);
        self::assertIsString($mlPlan['meta']['ranking_reason']);
        self::assertIsString($mlPlan['meta']['tradeoffs']);
        self::assertIsArray($mlPlan['meta']['heuristic_scores']);
        self::assertArrayHasKey('total_interest', $mlPlan['meta']['heuristic_scores']);
        self::assertArrayHasKey('months', $mlPlan['meta']['heuristic_scores']);
        self::assertIsArray($mlPlan['meta']['tradeoff_drivers']);
        self::assertArrayHasKey('currency', $mlPlan['meta']['tradeoff_drivers']);
        self::assertArrayHasKey('time_months', $mlPlan['meta']['tradeoff_drivers']);
        self::assertIsString($mlPlan['meta']['strategy_explanation']);
    }
}
