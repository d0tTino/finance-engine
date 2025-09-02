<?php

declare(strict_types=1);

namespace FireflyIII\Modules\AI\Simulations\Strategies;

/**
 * Machine-learning based strategy placeholder.
 */
class MlStrategy implements StrategyInterface
{
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

        if (!is_string($modelPath) || !is_file($modelPath)) {
            return null;
        }

        if (!class_exists('\\ONNXRuntime\\InferenceSession')) {
            return null;
        }

        try {
            $session     = new \ONNXRuntime\InferenceSession($modelPath);
            $result      = $session->run(['input' => $features]);
            $predictions = $result[0] ?? $result['output'] ?? null;

            if (is_array($predictions) && [] !== $predictions) {
                $index = array_search(max($predictions), $predictions, true);
                if (false !== $index) {
                    return (int) $index;
                }
            }
        } catch (\Throwable $e) {
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

        return null;
    }
}
