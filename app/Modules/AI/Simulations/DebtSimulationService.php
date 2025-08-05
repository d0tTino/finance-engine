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
 * Simulates several debt payoff strategies and produces metrics and schedules
 * for each plan. Currently supports the widely used "avalanche" and
 * "snowball" approaches.
 */
class DebtSimulationService
{
    public const RANKING_HEURISTIC = 'interest_then_months';

    private const STRATEGIES = ['avalanche', 'snowball'];

    /**
     * Run simulations for all strategies.
     *
     * @param string      $userId     The owning user identifier.
     * @param string|null $groupId    The user group identifier.
     * @param array $accounts   Array of accounts. Each entry must contain
     *                          `id`, `balance` and `rate` (APR percentage) and
     *                          may contain `name` and `min_payment`.
     * @param float $budget     Total monthly amount available for debt payments.
     * @param int   $maxOptions Maximum number of plans to return.
     *
     * @return array<int, array<string, mixed>>
     */
    public function simulate(string $userId, ?string $groupId, array $accounts, float $budget, int $maxOptions = 2): array
    {
        $hash     = hash('sha256', serialize([$accounts, $budget, $maxOptions]));
        $cacheKey = 'debt-sim-' . $hash;

        return UserScopedCache::remember(
            $userId,
            $groupId,
            $cacheKey,
            function () use ($accounts, $budget, $maxOptions): array {
                // Normalize account data for simulation service.
                $debts = array_map(static function (array $account): array {
                    return [
                        'name'        => (string) ($account['name'] ?? $account['id']),
                        'balance'     => (float) $account['balance'],
                        'rate'        => (float) $account['rate'] / 100,
                        'min_payment' => (float) ($account['min_payment'] ?? $account['minimum_payment'] ?? 0.0),
                    ];
                }, $accounts);

                $plans = [];
                foreach (self::STRATEGIES as $strategy) {
                    $plan    = $this->generateSchedule($debts, $budget, $strategy);
                    $plans[] = array_merge(['strategy' => $strategy], $plan);
                    if (count($plans) >= $maxOptions) {
                        break;
                    }
                }

                usort($plans, static function (array $a, array $b): int {
                    return [$a['total_interest'], $a['months']] <=> [$b['total_interest'], $b['months']];
                });

                $bestInterest  = $plans[0]['total_interest'] ?? 0.0;
                $bestMonths    = $plans[0]['months'] ?? 0;
                $worstInterest = max(array_column($plans, 'total_interest'));

                foreach ($plans as $i => &$plan) {
                    $plan['rank']                  = $i + 1;
                    $plan['is_optimal']            = 0 === $i;
                    $plan['interest_saved']        = $worstInterest - $plan['total_interest'];
                    $plan['time_to_payoff_months'] = $plan['months'];
                    $plan['cost_of_deviation']     = [
                        'currency'    => $plan['total_interest'] - $bestInterest,
                        'time_months' => $plan['months'] - $bestMonths,
                    ];
                    $plan['ranking_heuristic']     = self::RANKING_HEURISTIC;
                }

                return $plans;
            }
        );
    }

    /**
     * Generate the monthly schedule for one specific strategy.
     *
     * @param array  $debts
     * @param float  $monthlyBudget
     * @param string $strategy
     *
     * @return array<string, mixed>
     */
    private function generateSchedule(array $debts, float $monthlyBudget, string $strategy): array
    {
        $debts = array_map(static function (array $debt): array {
            $debt['balance']     = (float) $debt['balance'];
            $debt['rate']        = (float) $debt['rate'];
            $debt['min_payment'] = (float) $debt['min_payment'];

            return $debt;
        }, $debts);

        $schedule          = [];
        $totalInterest     = 0.0;
        $month             = 0;
        $cashFlowTimeline  = [];

        while ($this->hasBalance($debts)) {
            ++$month;
            $interestThisMonth = 0.0;
            foreach ($debts as &$debt) {
                if ($debt['balance'] <= 0) {
                    continue;
                }
                $interest           = $debt['balance'] * $debt['rate'] / 12;
                $debt['balance']   += $interest;
                $interestThisMonth += $interest;
            }
            unset($debt);

            $paymentPlan     = [];
            $remainingBudget = $monthlyBudget;

            // Pay minimums first.
            foreach ($debts as &$debt) {
                if ($debt['balance'] <= 0) {
                    $paymentPlan[$debt['name']] = 0.0;
                    continue;
                }
                $payment                     = min($debt['min_payment'], $debt['balance']);
                $debt['balance']            -= $payment;
                $paymentPlan[$debt['name']]  = $payment;
                $remainingBudget            -= $payment;
            }
            unset($debt);

            // Allocate any extra budget to targeted debt(s).
            while ($remainingBudget > 0 && $this->hasBalance($debts)) {
                $targetKey = $this->selectTargetDebt($debts, $strategy);
                if (null === $targetKey) {
                    break;
                }
                $target = &$debts[$targetKey];
                $extra  = min($remainingBudget, $target['balance']);
                if (!isset($paymentPlan[$target['name']])) {
                    $paymentPlan[$target['name']] = 0.0;
                }
                $target['balance']          -= $extra;
                $paymentPlan[$target['name']] += $extra;
                $remainingBudget            -= $extra;
                unset($target);
            }

            $totalPayment      = $monthlyBudget - $remainingBudget;
            $totalInterest    += $interestThisMonth;
            $cashFlowTimeline[] = ['month' => $month, 'cash_flow' => $remainingBudget];

            $balanceSnapshot = [];
            foreach ($debts as $debt) {
                $balanceSnapshot[$debt['name']] = max($debt['balance'], 0.0);
            }

            $schedule[] = [
                'month'     => $month,
                'payments'  => $paymentPlan,
                'balances'  => $balanceSnapshot,
                'interest'  => $interestThisMonth,
                'payment'   => $totalPayment,
                'cash_flow' => $remainingBudget,
            ];
        }

        return [
            'schedule'          => $schedule,
            'total_interest'    => $totalInterest,
            'months'            => $month,
            'monthly_cash_flow' => $cashFlowTimeline,
        ];
    }

    /**
     * Check if there is any outstanding balance left.
     */
    private function hasBalance(array $debts): bool
    {
        foreach ($debts as $debt) {
            if ($debt['balance'] > 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Select the index of the debt that should receive extra payments.
     */
    private function selectTargetDebt(array $debts, string $strategy): ?int
    {
        $indices     = array_keys($debts);
        $activeDebts = array_filter($indices, static function ($idx) use ($debts): bool {
            return $debts[$idx]['balance'] > 0.0;
        });
        if ([] === $activeDebts) {
            return null;
        }
        $key = null;
        if ('avalanche' === $strategy) {
            $maxRate = -INF;
            foreach ($activeDebts as $idx) {
                if ($debts[$idx]['rate'] > $maxRate) {
                    $maxRate = $debts[$idx]['rate'];
                    $key     = $idx;
                }
            }
        }
        if ('snowball' === $strategy) {
            $minBalance = INF;
            foreach ($activeDebts as $idx) {
                if ($debts[$idx]['balance'] < $minBalance) {
                    $minBalance = $debts[$idx]['balance'];
                    $key        = $idx;
                }
            }
        }

        return $key;
    }
}

