<?php

declare(strict_types=1);

namespace FireflyIII\Modules\AI\Simulations\Strategies;

interface StrategyInterface
{
    public function getName(): string;

    /**
     * Human-readable explanation of how the strategy allocates extra payments.
     */
    public function getDescription(): string;

    /**
     * Select the index of the debt that should receive extra payments.
     *
     * @param array<int, array<string, float>> $debts
     */
    public function selectTargetDebt(array $debts): ?int;
}
