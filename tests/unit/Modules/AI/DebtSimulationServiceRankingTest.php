<?php

declare(strict_types=1);

namespace Tests\unit\Modules\AI;

use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
use FireflyIII\Modules\AI\Simulations\Strategies\AvalancheStrategy;
use FireflyIII\Modules\AI\Simulations\Strategies\BalancedStrategy;
use FireflyIII\Modules\AI\Simulations\Strategies\SnowballStrategy;
use FireflyIII\Modules\AI\Simulations\Strategies\StrategyInterface;
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

        $rankingOrder = [];
        if ([] !== $plans) {
            $rankingOrder = array_keys($plans[0]['meta']['heuristic_scores']);
        }

        $converging = array_filter(
            $plans,
            static fn (array $plan): bool => 'non_converging' !== ($plan['status'] ?? 'ok')
        );
        $converging = array_values($converging);
        $sorted      = $converging;
        if ([] !== $sorted && [] !== $rankingOrder) {
            usort($sorted, static function (array $a, array $b) use ($rankingOrder): int {
                $left  = [];
                $right = [];
                foreach ($rankingOrder as $field) {
                    $left[]  = $a[$field];
                    $right[] = $b[$field];
                }

                return $left <=> $right;
            });
            self::assertSame($sorted, $converging);
        }

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
            if ([] !== $rankingOrder) {
                self::assertSame($rankingOrder, array_keys($plan['meta']['heuristic_scores']));
            }
            self::assertIsArray($plan['meta']['tradeoff_drivers']);
            self::assertArrayHasKey('currency', $plan['meta']['tradeoff_drivers']);
            self::assertArrayHasKey('time_months', $plan['meta']['tradeoff_drivers']);
        }
    }

    public function testFasterNonOptimalPlanUsesFewerMonthsMessaging(): void
    {
        Cache::flush();
        config(['ai.ranking_heuristic' => DebtSimulationService::RANKING_HEURISTIC]);

        $service = new StubDebtSimulationService();
        $user    = $this->createAuthenticatedUser();

        $accounts = [
            ['account_id' => 1, 'name' => 'Loan1', 'balance' => 1000.0, 'apr' => 0.10, 'min_payment' => 0.0],
        ];

        $plans = $service->simulate((string) $user->id, '1', $accounts, 300.0, 2);

        self::assertCount(2, $plans);

        $fastPlan = null;
        foreach ($plans as $plan) {
            if ('fast_payoff' === $plan['strategy']) {
                $fastPlan = $plan;
                break;
            }
        }

        self::assertNotNull($fastPlan);
        self::assertIsArray($fastPlan);
        self::assertFalse($fastPlan['is_optimal']);
        self::assertSame(-6, $fastPlan['cost_of_deviation']['time_months']);
        self::assertSame('loses 40.00 in interest savings but 6 fewer months', $fastPlan['meta']['tradeoffs']);
        self::assertSame('less interest saved despite faster payoff', $fastPlan['meta']['ranking_reason']);
    }

    public function testRankingHeuristicCanBeConfigured(): void
    {
        $strategies = [
            AvalancheStrategy::class,
            SnowballStrategy::class,
            BalancedStrategy::class,
        ];
        $accounts   = [
            ['account_id' => 1, 'name' => 'Loan1', 'balance' => 1000.0, 'apr' => 0.10, 'min_payment' => 0.0],
            ['account_id' => 2, 'name' => 'Loan2', 'balance' => 500.0, 'apr' => 0.05, 'min_payment' => 0.0],
        ];
        $user       = $this->createAuthenticatedUser();

        Cache::flush();
        config(['ai.ranking_heuristic' => DebtSimulationService::RANKING_HEURISTIC]);
        $defaultService = new DebtSimulationService($strategies);
        $defaultPlans   = $defaultService->simulate((string) $user->id, '1', $accounts, 300.0, 3);

        self::assertNotEmpty($defaultPlans);
        self::assertSame(['total_interest', 'months'], array_keys($defaultPlans[0]['meta']['heuristic_scores']));

        Cache::flush();
        config(['ai.ranking_heuristic' => 'months_then_interest']);
        $monthsService = new DebtSimulationService($strategies);
        $monthsPlans   = $monthsService->simulate((string) $user->id, '1', $accounts, 300.0, 3);

        self::assertNotEmpty($monthsPlans);
        self::assertSame(['months', 'total_interest'], array_keys($monthsPlans[0]['meta']['heuristic_scores']));
        self::assertSame('months_then_interest', $monthsPlans[0]['meta']['ranking_heuristic']);

        Cache::flush();
        config(['ai.ranking_heuristic' => DebtSimulationService::RANKING_HEURISTIC]);
        $stubInterestService = new StubDebtSimulationService();
        $interestPlans       = $stubInterestService->simulate((string) $user->id, '1', $accounts, 300.0, 2);
        $interestOrder       = array_column($interestPlans, 'strategy');

        Cache::flush();
        config(['ai.ranking_heuristic' => 'months_then_interest']);
        $stubMonthsService = new StubDebtSimulationService();
        $monthsPlansStub   = $stubMonthsService->simulate((string) $user->id, '1', $accounts, 300.0, 2);
        $monthsOrderStub   = array_column($monthsPlansStub, 'strategy');

        self::assertSame(['low_interest', 'fast_payoff'], $interestOrder);
        self::assertSame(['fast_payoff', 'low_interest'], $monthsOrderStub);
        self::assertSame('months_then_interest', $monthsPlansStub[0]['meta']['ranking_heuristic']);

        foreach ($monthsPlansStub as $plan) {
            self::assertSame(['months', 'total_interest'], array_keys($plan['meta']['heuristic_scores']));
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

final class StubLowInterestStrategy implements StrategyInterface
{
    public function getName(): string
    {
        return 'low_interest';
    }

    public function getExplanation(): string
    {
        return 'Prioritizes the lowest overall interest paid.';
    }

    public function reset(): void
    {
    }

    public function selectTargetDebt(array $debts): ?int
    {
        return 0;
    }
}

final class StubFastPayoffStrategy implements StrategyInterface
{
    public function getName(): string
    {
        return 'fast_payoff';
    }

    public function getExplanation(): string
    {
        return 'Trades higher interest for a shorter payoff window.';
    }

    public function reset(): void
    {
    }

    public function selectTargetDebt(array $debts): ?int
    {
        return 0;
    }
}

final class StubDebtSimulationService extends DebtSimulationService
{
    public function __construct()
    {
        parent::__construct([
            StubLowInterestStrategy::class,
            StubFastPayoffStrategy::class,
        ]);
    }

    protected function generateSchedule(array $debts, float $monthlyBudget, StrategyInterface $strategy): array
    {
        if ($strategy instanceof StubLowInterestStrategy) {
            return [
                'schedule'          => [],
                'total_interest'    => 100.0,
                'months'            => 24,
                'monthly_cash_flow' => [],
                'recommendations'   => [],
                'status'            => 'ok',
            ];
        }

        if ($strategy instanceof StubFastPayoffStrategy) {
            return [
                'schedule'          => [],
                'total_interest'    => 140.0,
                'months'            => 18,
                'monthly_cash_flow' => [],
                'recommendations'   => [],
                'status'            => 'ok',
            ];
        }

        return parent::generateSchedule($debts, $monthlyBudget, $strategy);
    }
}
