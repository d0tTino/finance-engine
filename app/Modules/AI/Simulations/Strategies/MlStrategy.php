<?php

declare(strict_types=1);

namespace FireflyIII\Modules\AI\Simulations\Strategies;

/**
 * Machine-learning based strategy placeholder.
 */
class MlStrategy implements StrategyInterface
{
    /**
     * @var \ONNXRuntime\InferenceSession|null
     */
    private static $session = null;

    private static ?string $sessionModelPath = null;

    public function getName(): string
    {
        return 'ml';
    }

    public function getExplanation(): string
    {
        return 'Uses a machine learning model to prioritize debts based on learned patterns.';
    }

    public function reset(): void
    {
    }

    /**
     * Extract numerical features for model inference.
     *
     * @param array<int, array<string, float>> $debts
     *
     * @return array<int, array<float>>
     */
    protected function extractFeatures(array $debts): array
    {
        $features = [];
        foreach ($debts as $debt) {
            $features[] = [
                $debt['balance'],
                $debt['rate'],
                $debt['min_payment'],
            ];
        }

        return $features;
    }

    /**
     * Hook for model inference returning the selected debt index.
     * Replace with real model logic.
     *
     * @param array<int, array<float>> $features
     */
    protected function infer(array $features): ?int
    {
        $modelPath = config('ai.ml_model_path');
        $threshold = (float) config('ai.ml_model_threshold', 0.0);

        if (!is_string($modelPath) || !is_file($modelPath)) {
            self::$session = null;
            self::$sessionModelPath = null;
            return null;
        }

        if (!class_exists('\\ONNXRuntime\\InferenceSession')) {
            self::$session = null;
            self::$sessionModelPath = null;
            return null;
        }

        try {
            if (null === self::$session || $modelPath !== self::$sessionModelPath) {
                self::$session           = new \ONNXRuntime\InferenceSession($modelPath);
                self::$sessionModelPath  = $modelPath;
            }

            $session     = self::$session;
            $result      = $session->run(['input' => $features]);
            $predictions = $result[0] ?? $result['output'] ?? null;

            if (is_array($predictions) && [] !== $predictions) {
                $max    = max($predictions);
                $index  = array_search($max, $predictions, true);
                if (false !== $index && $max >= $threshold) {
                    return (int) $index;
                }
            }
        } catch (\Throwable $e) {
            self::$session = null;
            self::$sessionModelPath = null;
            report($e);
        }

        return null;
    }

    public function selectTargetDebt(array $debts): ?int
    {
        $features = $this->extractFeatures($debts);
        $prediction = $this->infer($features);
        if (null !== $prediction && isset($debts[$prediction]) && $debts[$prediction]['balance'] > 0.0) {
            return $prediction;
        }

        $fallback = new AvalancheStrategy();

        return $fallback->selectTargetDebt($debts);
    }
}
