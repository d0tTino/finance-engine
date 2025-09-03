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
        config(['ai.ranking_heuristic' => DebtSimulationService::RANKING_HEURISTIC]);

        $service = new DebtSimulationService([
            AvalancheStrategy::class,
            SnowballStrategy::class,
            BalancedStrategy::class,
        ]);
        $user    = $this->createAuthenticatedUser();

        $accounts = [
            ['account_id' => 1, 'name' => 'Loan1', 'balance' => 1000.0, 'apr' => 0.10, 'min_payment' => 0.0],
            ['account_id' => 2, 'name' => 'Loan2', 'balance' => 500.0, 'apr' => 0.05, 'min_payment' => 0.0],
        ];

        $plans = $service->simulate((string) $user->id, '1', $accounts, 300.0, 3);

        $converging = array_filter(
            $plans,
            static fn (array $plan): bool => 'non_converging' !== ($plan['status'] ?? 'ok')
        );
        $sorted = $converging;
        usort($sorted, static fn (array $a, array $b): int => [$a['total_interest'], $a['months']] <=> [$b['total_interest'], $b['months']]);
        self::assertSame($sorted, array_values($converging));

        $bestInterest = $plans[0]['total_interest'];
        $bestMonths   = $plans[0]['months'];
        $interestVals = array_column($plans, 'total_interest');
        $baselineInterest = [] === $interestVals ? 0 : max($interestVals);


        foreach ($plans as $plan) {
            $expectedCurrency      = $plan['total_interest'] - $bestInterest;
            $expectedMonths        = $plan['months'] - $bestMonths;
            $expectedInterestSaved = $baselineInterest - $plan['total_interest'];

            self::assertEqualsWithDelta($expectedInterestSaved, $plan['interest_saved'], 0.0001);
            self::assertEqualsWithDelta($expectedCurrency, $plan['cost_of_deviation']['currency'], 0.0001);
            self::assertEquals($expectedMonths, $plan['cost_of_deviation']['time_months']);
            self::assertGreaterThanOrEqual(0.0, $plan['interest_saved']);

            self::assertSame(config('ai.ranking_heuristic'), $plan['meta']['ranking_heuristic']);
            self::assertIsString($plan['meta']['ranking_reason']);
            self::assertIsString($plan['meta']['tradeoffs']);
            self::assertIsArray($plan['meta']['heuristic_scores']);
            self::assertArrayHasKey('total_interest', $plan['meta']['heuristic_scores']);
            self::assertArrayHasKey('months', $plan['meta']['heuristic_scores']);
            self::assertIsArray($plan['meta']['tradeoff_drivers']);
            self::assertArrayHasKey('currency', $plan['meta']['tradeoff_drivers']);
            self::assertArrayHasKey('time_months', $plan['meta']['tradeoff_drivers']);
        }
    }

    public function testRankingHeuristicCanBeConfigured(): void
    {
        Cache::flush();
        config(['ai.ranking_heuristic' => 'months_then_interest']);

        $service = new DebtSimulationService();
        $user    = $this->createAuthenticatedUser();

        $accounts = [
            ['account_id' => 1, 'name' => 'Loan1', 'balance' => 1000.0, 'apr' => 0.10, 'min_payment' => 0.0],
            ['account_id' => 2, 'name' => 'Loan2', 'balance' => 500.0, 'apr' => 0.05, 'min_payment' => 0.0],
        ];

        $plans = $service->simulate((string) $user->id, '1', $accounts, 300.0, 2);

        foreach ($plans as $plan) {
            self::assertSame('months_then_interest', $plan['meta']['ranking_heuristic']);
            self::assertIsArray($plan['meta']['tradeoff_drivers']);
            self::assertArrayHasKey('currency', $plan['meta']['tradeoff_drivers']);
            self::assertArrayHasKey('time_months', $plan['meta']['tradeoff_drivers']);
        }

        config(['ai.ranking_heuristic' => DebtSimulationService::RANKING_HEURISTIC]);
    }

    public function testBestPlansReturnedRegardlessOfStrategyOrder(): void
    {
        Cache::flush();

        $user       = $this->createAuthenticatedUser();
        $accounts   = [
            ['account_id' => 1, 'name' => 'Loan1', 'balance' => 1000.0, 'apr' => 0.10, 'min_payment' => 0.0],
            ['account_id' => 2, 'name' => 'Loan2', 'balance' => 500.0, 'apr' => 0.05, 'min_payment' => 0.0],
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
