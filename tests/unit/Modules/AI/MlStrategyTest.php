<?php

declare(strict_types=1);

namespace ONNXRuntime {
    class InferenceSession
    {
        public static bool $shouldThrow = false;
        public static array $output = [[0.0]];
        private string $path;

        public function __construct(string $path)
        {
            $this->path = $path;
        }

        public function getPath(): string
        {
            return $this->path;
        }

        public function run(array $inputs): array
        {
            if (self::$shouldThrow) {
                throw new \RuntimeException('inference error');
            }

            return self::$output;
        }
    }
}

namespace Tests\unit\Modules\AI {

    use FireflyIII\Modules\AI\Simulations\Strategies\MlStrategy;
    use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
    use Illuminate\Support\Facades\Cache;
    use Tests\integration\TestCase;
    use function Safe\tempnam;

    /**
     * @group unit-test
     * @group ai
     */
    final class MlStrategyTest extends TestCase
    {
        public function testSuccessfulInference(): void
        {
            $original = config('ai.ml_model_path');
            $modelPath = tempnam(sys_get_temp_dir(), 'model');
            config(['ai.ml_model_path' => $modelPath]);

            \ONNXRuntime\InferenceSession::$shouldThrow = false;
            \ONNXRuntime\InferenceSession::$output = [[0.1, 0.7, 0.2]];

            $strategy = new MlStrategy();
            $debts    = [
                ['balance' => 100.0, 'rate' => 1.0, 'min_payment' => 10.0],
                ['balance' => 200.0, 'rate' => 2.0, 'min_payment' => 20.0],
                ['balance' => 50.0, 'rate' => 3.0, 'min_payment' => 5.0],
            ];

            $result = $strategy->selectTargetDebt($debts);

            self::assertSame(1, $result);

            config(['ai.ml_model_path' => $original]);
        }

        public function testInferenceFallbackOnError(): void
        {
            $original = config('ai.ml_model_path');
            $modelPath = tempnam(sys_get_temp_dir(), 'model');
            config(['ai.ml_model_path' => $modelPath]);

            \ONNXRuntime\InferenceSession::$shouldThrow = true;

            $strategy = new MlStrategy();
            $debts    = [
                ['balance' => 100.0, 'rate' => 1.0, 'min_payment' => 10.0],
            ];

            $result = $strategy->selectTargetDebt($debts);

            self::assertNull($result);

            \ONNXRuntime\InferenceSession::$shouldThrow = false;
            config(['ai.ml_model_path' => $original]);
        }

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
}

