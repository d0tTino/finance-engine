<?php

declare(strict_types=1);

namespace Tests\unit\Modules\AI;

use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
use FireflyIII\Modules\AI\Simulations\Strategies\AvalancheStrategy;
use FireflyIII\Modules\AI\Simulations\Strategies\StrategyInterface;
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

    public function testPlansDoNotExceedMaxOptions(): void
    {
        Cache::flush();
        $service = new DebtSimulationService([
            AvalancheStrategy::class,
            PassiveNonConvergingTestStrategy::class,
        ]);

        $accounts = [
            ['account_id' => 1, 'balance' => 1000.0, 'apr' => 0.10, 'min_payment' => 0.0],
        ];
        $budget     = 100.0;
        $maxOptions = 1;

        $plans = $service->simulate('user', 'group', $accounts, $budget, $maxOptions);

        self::assertCount($maxOptions, $plans);
        self::assertSame('avalanche', $plans[0]['strategy']);
        self::assertSame('ok', $plans[0]['status']);
    }
}

final class PassiveNonConvergingTestStrategy implements StrategyInterface
{
    public function getName(): string
    {
        return 'passive_non_converging_test';
    }

    public function getExplanation(): string
    {
        return 'Leaves extra funds unused so the plan fails to converge.';
    }

    public function reset(): void
    {
    }

    public function selectTargetDebt(array $debts): ?int
    {
        return null;
    }
}
