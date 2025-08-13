<?php

declare(strict_types=1);

namespace Tests\unit\Modules\AI;

use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
use FireflyIII\Modules\AI\Simulations\Strategies\AvalancheStrategy;
use FireflyIII\Modules\AI\Simulations\Strategies\BalancedStrategy;
use FireflyIII\Modules\AI\Simulations\Strategies\SnowballStrategy;
use Illuminate\Support\Facades\Cache;
use Tests\integration\TestCase;

/**
 * @group unit-test
 * @group ai
 */
final class DebtSimulationServiceRankingTest extends TestCase
{
    public function testPlansAreRankedAndAnnotated(): void
    {
        Cache::flush();

        $service = new DebtSimulationService();
        $user    = $this->createAuthenticatedUser();

        $accounts = [
            ['account_id' => 1, 'name' => 'Loan1', 'balance' => 1000.0, 'apr' => 10.0, 'min_payment' => 0.0],
            ['account_id' => 2, 'name' => 'Loan2', 'balance' => 500.0, 'apr' => 5.0, 'min_payment' => 0.0],
        ];

        $plans = $service->simulate((string) $user->id, '1', $accounts, 300.0, 3);

        $sorted = $plans;
        usort($sorted, static fn (array $a, array $b): int => [$a['total_interest'], $a['months']] <=> [$b['total_interest'], $b['months']]);
        self::assertSame($sorted, $plans);

        $bestInterest = $plans[0]['total_interest'];
        $bestMonths   = $plans[0]['months'];

        foreach ($plans as $plan) {
            $expectedCurrency = $plan['total_interest'] - $bestInterest;
            $expectedMonths   = $plan['months'] - $bestMonths;

            self::assertEqualsWithDelta($expectedCurrency, $plan['cost_of_deviation']['currency'], 0.0001);
            self::assertEquals($expectedMonths, $plan['cost_of_deviation']['time_months']);

            self::assertSame(DebtSimulationService::RANKING_HEURISTIC, $plan['meta']['ranking_heuristic']);
            self::assertIsString($plan['meta']['ranking_reason']);
            self::assertIsString($plan['meta']['tradeoffs']);
        }
    }

    public function testBestPlansReturnedRegardlessOfStrategyOrder(): void
    {
        Cache::flush();

        $user       = $this->createAuthenticatedUser();
        $accounts   = [
            ['account_id' => 1, 'name' => 'Loan1', 'balance' => 1000.0, 'apr' => 10.0, 'min_payment' => 0.0],
            ['account_id' => 2, 'name' => 'Loan2', 'balance' => 500.0, 'apr' => 5.0, 'min_payment' => 0.0],
        ];

        $strategies = [SnowballStrategy::class, BalancedStrategy::class, AvalancheStrategy::class];

        $serviceA = new DebtSimulationService($strategies);
        $plansA   = $serviceA->simulate((string) $user->id, '1', $accounts, 300.0, 2);

        $serviceB = new DebtSimulationService(array_reverse($strategies));
        $plansB   = $serviceB->simulate((string) $user->id, '1', $accounts, 300.0, 2);

        $strategiesA = array_column($plansA, 'strategy');
        $strategiesB = array_column($plansB, 'strategy');

        self::assertSame($strategiesA, $strategiesB);
        self::assertSame('avalanche', $strategiesA[0]);
    }
}
