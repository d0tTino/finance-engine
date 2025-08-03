<?php

/*
 * DebtSimulationService.php
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * Copyright (c) 2025
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

namespace FireflyIII\Modules\AI\Simulations;

use FireflyIII\Support\Cache\UserScopedCache;

/**
 * Class DebtSimulationService
 *
 * Generates simple payoff plans for a list of debt accounts.  Two strategies
 * are supported: the "avalanche" method which prioritises the highest interest
 * rate and the "snowball" method which targets the smallest balance first.
 *
 * The implementation is intentionally lightweight – it provides deterministic
 * and easily testable output rather than a full financial model.  Each plan is
 * returned with basic metrics and a deviation cost relative to the cheapest
 * option.
 */
class DebtSimulationService
{
    /**
     * Run the simulation.
     *
     * @param int   $userId        The owning user identifier.
     * @param int   $groupId       The user group identifier.
     * @param array $accounts      Array of accounts. Each entry must contain
     *                             `id`, `balance` and `rate` (APR percentage).
     * @param float $budget        Monthly budget available for repayments.
     * @param int   $maxOptions    Maximum number of plans to return.
     *
     * @return array<int, array<string, mixed>>
     */
    public function simulate(int $userId, int $groupId, array $accounts, float $budget, int $maxOptions = 2): array
    {
        $hash     = hash('sha256', serialize([$accounts, $budget, $maxOptions]));
        $cacheKey = 'debt-sim-' . $hash;

        return UserScopedCache::remember(
            $userId,
            $groupId,
            $cacheKey,
            function () use ($accounts, $budget, $maxOptions): array {
                // Generate plans using two common strategies: avalanche and snowball.
                $strategies = [
                    'avalanche' => fn(array $a, array $b) => $b['rate'] <=> $a['rate'],
                    'snowball'  => fn(array $a, array $b) => $a['balance'] <=> $b['balance'],
                ];

                $plans = [];
                foreach ($strategies as $name => $sort) {
                    $ordered    = $accounts;
                    usort($ordered, $sort);
                    $simulation = $this->simulateOrder($ordered, $budget);
                    $plans[]    = [
                        'strategy' => $name,
                        'order'    => array_column($ordered, 'id'),
                        'metrics'  => $simulation,
                    ];
                    if (count($plans) >= $maxOptions) {
                        break;
                    }
                }

                // Rank plans by total interest paid (lowest is best).
                usort($plans, static fn($a, $b) => $a['metrics']['interest'] <=> $b['metrics']['interest']);
                $min = $plans[0]['metrics']['interest'] ?? 0.0;
                foreach ($plans as $idx => &$plan) {
                    $plan['rank']           = $idx + 1;
                    $plan['deviation_cost'] = $plan['metrics']['interest'] - $min;
                }

                return $plans;
            }
        );
    }

    /**
     * Simulate paying off accounts in the given order.
     *
     * @param array<int, array{id:int, balance:float, rate:float}> $accounts
     * @param float                                                $budget
     *
     * @return array<string, float|int>
     */
    private function simulateOrder(array $accounts, float $budget): array
    {
        $balances = array_map(static fn($a) => $a['balance'], $accounts);
        $rates    = array_map(static fn($a) => $a['rate'] / 100, $accounts);
        $months   = 0;
        $interest = 0.0;

        while (array_sum($balances) > 0.01 && $months < 1200) { // cap to prevent infinite loops
            // Apply monthly interest
            foreach ($balances as $i => $balance) {
                if ($balance <= 0) {
                    continue;
                }
                $charge      = $balance * $rates[$i] / 12;
                $balances[$i] += $charge;
                $interest    += $charge;
            }

            $remaining = $budget;
            foreach ($balances as $i => $balance) {
                if ($balance <= 0 || $remaining <= 0) {
                    continue;
                }
                $pay            = min($balance, $remaining);
                $balances[$i]  -= $pay;
                $remaining     -= $pay;
            }
            ++$months;
        }

        return [
            'months'   => $months,
            'interest' => $interest,
        ];
    }
}
