<?php

/*
 * AvalancheStrategy.php
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Modules\AI\Simulations\Strategies;

/**
 * Highest interest rate first (avalanche).
 */
class AvalancheStrategy implements DebtStrategyInterface
{
    #[\Override]
    public function getName(): string
    {
        return 'avalanche';
    }

    #[\Override]
    public function selectTargetDebt(array $debts): ?int
    {
        $indices     = array_keys($debts);
        $activeDebts = array_filter($indices, static fn ($idx): bool => $debts[$idx]['balance'] > 0.0);
        if ([] === $activeDebts) {
            return null;
        }

        $key     = null;
        $maxRate = -INF;
        foreach ($activeDebts as $idx) {
            if ($debts[$idx]['rate'] > $maxRate) {
                $maxRate = $debts[$idx]['rate'];
                $key     = $idx;
            }
        }

        return $key;
    }
}
