<?php

declare(strict_types=1);

namespace ONNXRuntime {
    class InferenceSession
    {
        public static bool $shouldThrow = false;
        public static array $output = [[0.0]];
        public static int $instances = 0;
        private string $path;

        public function __construct(string $path)
        {
            self::$instances++;
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
    use ReflectionClass;
    use Tests\integration\TestCase;
    use function Safe\tempnam;

    /**
     * @group unit-test
     * @group ai
     */
    final class MlStrategyTest extends TestCase
    {
        protected function tearDown(): void
        {
            parent::tearDown();

            \ONNXRuntime\InferenceSession::$shouldThrow = false;
            \ONNXRuntime\InferenceSession::$output      = [[0.0]];
            \ONNXRuntime\InferenceSession::$instances   = 0;

            $reflection = new ReflectionClass(MlStrategy::class);

            if ($reflection->hasProperty('session')) {
                $property = $reflection->getProperty('session');
                $property->setAccessible(true);
                $property->setValue(null);
            }

            if ($reflection->hasProperty('sessionModelPath')) {
                $property = $reflection->getProperty('sessionModelPath');
                $property->setAccessible(true);
                $property->setValue(null);
            }
        }

        public function testSuccessfulInference(): void
        {
            $original = config('ai.ml_model_path');
            $originalThreshold = config('ai.ml_model_threshold');
            $modelPath = tempnam(sys_get_temp_dir(), 'model');
            config(['ai.ml_model_path' => $modelPath, 'ai.ml_model_threshold' => 0.5]);

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

            \ONNXRuntime\InferenceSession::$output = [[0.0]];

            config(['ai.ml_model_path' => $original, 'ai.ml_model_threshold' => $originalThreshold]);
        }

        public function testInferenceSessionIsReusedAcrossCalls(): void
        {
            $original          = config('ai.ml_model_path');
            $originalThreshold = config('ai.ml_model_threshold');
            $modelPath         = tempnam(sys_get_temp_dir(), 'model');
            config(['ai.ml_model_path' => $modelPath, 'ai.ml_model_threshold' => 0.5]);

            \ONNXRuntime\InferenceSession::$shouldThrow = false;
            \ONNXRuntime\InferenceSession::$output      = [[0.8, 0.1, 0.1]];
            \ONNXRuntime\InferenceSession::$instances   = 0;

            $strategy = new MlStrategy();
            $debts    = [
                ['balance' => 100.0, 'rate' => 1.0, 'min_payment' => 10.0],
                ['balance' => 200.0, 'rate' => 2.0, 'min_payment' => 20.0],
                ['balance' => 50.0, 'rate' => 3.0, 'min_payment' => 5.0],
            ];

            $firstResult  = $strategy->selectTargetDebt($debts);
            $secondResult = $strategy->selectTargetDebt($debts);

            self::assertSame(0, $firstResult);
            self::assertSame($firstResult, $secondResult);
            self::assertSame(1, \ONNXRuntime\InferenceSession::$instances);

            config(['ai.ml_model_path' => $original, 'ai.ml_model_threshold' => $originalThreshold]);
        }

        public function testInferenceFallbackOnError(): void
        {
            $original           = config('ai.ml_model_path');
            $originalThreshold  = config('ai.ml_model_threshold');
            $modelPath = tempnam(sys_get_temp_dir(), 'model');
            config(['ai.ml_model_path' => $modelPath, 'ai.ml_model_threshold' => 0.5]);

            \ONNXRuntime\InferenceSession::$shouldThrow = true;

            $strategy = new MlStrategy();
            $debts    = [
                ['balance' => 100.0, 'rate' => 1.0, 'min_payment' => 10.0],
                ['balance' => 200.0, 'rate' => 2.0, 'min_payment' => 20.0],
            ];

            $result = $strategy->selectTargetDebt($debts);

            self::assertSame(1, $result);

            \ONNXRuntime\InferenceSession::$shouldThrow = false;
            \ONNXRuntime\InferenceSession::$output      = [[0.0]];
            config(['ai.ml_model_path' => $original, 'ai.ml_model_threshold' => $originalThreshold]);
        }

        public function testInferenceFallbackOnLowConfidence(): void
        {
            $original           = config('ai.ml_model_path');
            $originalThreshold  = config('ai.ml_model_threshold');
            $modelPath = tempnam(sys_get_temp_dir(), 'model');
            config(['ai.ml_model_path' => $modelPath, 'ai.ml_model_threshold' => 0.8]);

            \ONNXRuntime\InferenceSession::$shouldThrow = false;
            \ONNXRuntime\InferenceSession::$output      = [[0.4, 0.3, 0.3]];

            $strategy = new MlStrategy();
            $debts    = [
                ['balance' => 100.0, 'rate' => 1.0, 'min_payment' => 10.0],
                ['balance' => 200.0, 'rate' => 2.0, 'min_payment' => 20.0],
                ['balance' => 50.0, 'rate' => 0.5, 'min_payment' => 5.0],
            ];

            $result = $strategy->selectTargetDebt($debts);

            self::assertSame(1, $result);

            \ONNXRuntime\InferenceSession::$output = [[0.0]];

            config(['ai.ml_model_path' => $original, 'ai.ml_model_threshold' => $originalThreshold]);
        }

        public function testStrategyIsInvokedAndRankedWithAnnotations(): void
        {
            Cache::flush();

            $service = new DebtSimulationService();
            $user    = $this->createAuthenticatedUser();

            $accounts = [
                ['account_id' => 1, 'name' => 'Loan1', 'balance' => 1000.0, 'apr' => 0.10, 'min_payment' => 0.0],
                ['account_id' => 2, 'name' => 'Loan2', 'balance' => 500.0, 'apr' => 0.05, 'min_payment' => 0.0],
            ];

            $plans = $service->simulate((string) $user->id, '1', $accounts, 300.0, 4);

            self::assertCount(4, $plans);

            $strategies = array_column($plans, 'strategy');
            self::assertContains('ml', $strategies);
            $mlIndex = array_search('ml', $strategies, true);
            self::assertNotFalse($mlIndex);
            $mlPlan = $plans[$mlIndex];

            // ensure ml strategy produced a converging plan
            self::assertIsInt($mlPlan['rank']);
            self::assertSame('ok', $mlPlan['status']);

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
            self::assertArrayHasKey('value', $mlPlan['meta']['tradeoff_drivers']['currency']);
            self::assertArrayHasKey('display_name', $mlPlan['meta']['tradeoff_drivers']['currency']);
            self::assertArrayHasKey('value', $mlPlan['meta']['tradeoff_drivers']['time_months']);
            self::assertArrayHasKey('display_name', $mlPlan['meta']['tradeoff_drivers']['time_months']);
            self::assertIsString($mlPlan['meta']['strategy_explanation']);
            self::assertIsArray($mlPlan['accounts']);
        }
    }
}

