<?php

declare(strict_types=1);

namespace FireflyIII\Modules\AI\Simulations\Strategies;

/**
 * Distributes extra payments proportionally across outstanding debts.
 *
 * The implementation uses a smooth weighted round-robin algorithm so that
 * debts with larger balances receive extra payments more frequently than
 * those with smaller balances.
 */
class BalancedStrategy implements StrategyInterface
{
    /**
     * Internal weights used for the smooth weighted round-robin algorithm.
     *
     * @var array<int, float>
     */
    private array $currentWeights = [];

    public function getName(): string
    {
        return 'balanced';
    }

    public function selectTargetDebt(array $debts): ?int
    {
        $activeBalances = [];
        foreach ($debts as $idx => $debt) {
            if ($debt['balance'] > 0.0) {
                $activeBalances[$idx]       = $debt['balance'];
                $this->currentWeights[$idx] = $this->currentWeights[$idx] ?? 0.0;
            } else {
                unset($this->currentWeights[$idx]);
            }
        }

        if ([] === $activeBalances) {
            return null;
        }

        $totalWeight = array_sum($activeBalances);
        $selected    = null;

        foreach ($activeBalances as $idx => $balance) {
            $this->currentWeights[$idx] += $balance;
            if (null === $selected || $this->currentWeights[$idx] > $this->currentWeights[$selected]) {
                $selected = $idx;
            }
        }

        $this->currentWeights[$selected] -= $totalWeight;

        return $selected;
    }
}
