<?php

/*
 * DebtSimulationTest.php
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 */

declare(strict_types=1);

namespace Tests\unit;

use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
use Tests\integration\TestCase;

/**
 * @group unit-test
 * @group ai
 *
 * @internal
 */
final class DebtSimulationTest extends TestCase
{
    public function testGeneratesIdenticalPlansForSingleAccount(): void
    {
        $user = $this->createAuthenticatedUser();
        $service = new DebtSimulationService();

        $accounts = [
            ['account_id' => 1, 'balance' => 1000.0, 'apr' => 10.0, 'min_payment' => 0.0],
        ];
        $budget = 200.0;

        $plans = $service->simulate((string) $user->id, '1', $accounts, $budget, 3);

        self::assertCount(3, $plans);

        foreach ($plans as $plan) {
            self::assertArrayHasKey('schedule', $plan);
            self::assertIsArray($plan['schedule']);
            self::assertArrayHasKey('recommendations', $plan);
            self::assertIsArray($plan['recommendations']);
            self::assertArrayHasKey('cost_of_deviation', $plan);
            self::assertArrayHasKey('currency', $plan['cost_of_deviation']);
            self::assertArrayHasKey('time_months', $plan['cost_of_deviation']);
            self::assertArrayHasKey('1', $plan['schedule'][0]['payments']);
        }

        $mapped = [];
        foreach ($plans as $plan) {
            $mapped[$plan['strategy']] = $plan;
        }

        self::assertArrayHasKey('balanced', $mapped);

        self::assertEquals(6, $mapped['avalanche']['time_to_payoff_months']);
        self::assertEquals(6, $mapped['snowball']['time_to_payoff_months']);

        self::assertEqualsWithDelta(0.0, $mapped['avalanche']['interest_saved'], 0.0001);
        self::assertEqualsWithDelta(0.0, $mapped['snowball']['interest_saved'], 0.0001);

        self::assertTrue($mapped['avalanche']['is_optimal']);
        self::assertFalse($mapped['snowball']['is_optimal']);
        self::assertArrayHasKey('rank', $mapped['balanced']);

        self::assertEquals(0.0, $mapped['avalanche']['cost_of_deviation']['currency']);
        self::assertEquals(0.0, $mapped['snowball']['cost_of_deviation']['currency']);
        self::assertEquals(0, $mapped['avalanche']['cost_of_deviation']['time_months']);
        self::assertEquals(0, $mapped['snowball']['cost_of_deviation']['time_months']);

        self::assertCount(6, $mapped['avalanche']['schedule']);
        self::assertCount(6, $mapped['snowball']['schedule']);
    }

    public function testRanksAvalancheAheadOfSnowballWithMetrics(): void
    {
        $user = $this->createAuthenticatedUser();
        $service = new DebtSimulationService();

        $accounts = [
            ['account_id' => 1, 'name' => 'Loan1', 'balance' => 1000.0, 'apr' => 10.0, 'min_payment' => 0.0],
            ['account_id' => 2, 'name' => 'Loan2', 'balance' => 500.0, 'apr' => 5.0, 'min_payment' => 0.0],
        ];
        $budget = 300.0;

        $plans = $service->simulate((string) $user->id, '1', $accounts, $budget, 3);
        foreach ($plans as $plan) {
            self::assertArrayHasKey('schedule', $plan);
            self::assertIsArray($plan['schedule']);
            self::assertArrayHasKey('recommendations', $plan);
            self::assertIsArray($plan['recommendations']);
            self::assertArrayHasKey('cost_of_deviation', $plan);
            self::assertArrayHasKey('currency', $plan['cost_of_deviation']);
            self::assertArrayHasKey('time_months', $plan['cost_of_deviation']);
        }
        $mapped = [];
        foreach ($plans as $plan) {
            $mapped[$plan['strategy']] = $plan;
        }

        self::assertArrayHasKey('balanced', $mapped);

        self::assertEquals(1, $mapped['avalanche']['rank']);
        self::assertGreaterThan(1, $mapped['snowball']['rank']);
        self::assertGreaterThan(1, $mapped['balanced']['rank']);

        self::assertTrue($mapped['avalanche']['is_optimal']);
        self::assertFalse($mapped['snowball']['is_optimal']);
        self::assertFalse($mapped['balanced']['is_optimal']);

        self::assertEqualsWithDelta(7.0831535569, $mapped['avalanche']['interest_saved'], 0.0001);
        self::assertEqualsWithDelta(0.0, $mapped['snowball']['interest_saved'], 0.0001);

        self::assertEqualsWithDelta(0.0, $mapped['avalanche']['cost_of_deviation']['currency'], 0.0001);
        self::assertEqualsWithDelta(7.0831535569, $mapped['snowball']['cost_of_deviation']['currency'], 0.0001);
        self::assertEquals(0, $mapped['avalanche']['cost_of_deviation']['time_months']);
        self::assertEquals(0, $mapped['snowball']['cost_of_deviation']['time_months']);

        self::assertEquals(6, $mapped['avalanche']['time_to_payoff_months']);
        self::assertEquals(6, $mapped['snowball']['time_to_payoff_months']);

        self::assertSame(
            DebtSimulationService::RANKING_HEURISTIC,
            $mapped['avalanche']['meta']['ranking_heuristic']
        );
        self::assertIsString($mapped['avalanche']['meta']['ranking_reason']);
        self::assertIsString($mapped['avalanche']['meta']['tradeoffs']);
    }

    public function testMetaContainsRankingReasonAndTradeoffs(): void
    {
        $user    = $this->createAuthenticatedUser();
        $service = new DebtSimulationService();

        $accounts = [
            ['account_id' => 1, 'name' => 'Loan1', 'balance' => 1000.0, 'apr' => 10.0, 'min_payment' => 0.0],
            ['account_id' => 2, 'name' => 'Loan2', 'balance' => 500.0, 'apr' => 5.0, 'min_payment' => 0.0],
        ];
        $budget = 300.0;

        $plans = $service->simulate((string) $user->id, '1', $accounts, $budget, 2);

        $actions = array_map(
            static function (array $plan): array {
                return [
                    'cost_of_deviation' => $plan['cost_of_deviation'],
                    'meta'              => [
                        'ranking_heuristic' => $plan['meta']['ranking_heuristic'] ?? '',
                        'ranking_reason'    => $plan['meta']['ranking_reason'] ?? '',
                        'tradeoffs'         => $plan['meta']['tradeoffs'] ?? $plan['cost_of_deviation'],
                    ],
                ];
            },
            $plans
        );

        foreach ($actions as $action) {
            self::assertArrayHasKey('ranking_reason', $action['meta']);
            self::assertSame(DebtSimulationService::RANKING_HEURISTIC, $action['meta']['ranking_heuristic']);
            self::assertIsString($action['meta']['ranking_reason']);
            self::assertArrayHasKey('tradeoffs', $action['meta']);
            self::assertIsString($action['meta']['tradeoffs']);
            self::assertArrayHasKey('currency', $action['cost_of_deviation']);
            self::assertArrayHasKey('time_months', $action['cost_of_deviation']);
        }
    }
}
