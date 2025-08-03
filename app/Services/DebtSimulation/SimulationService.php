<?php

/**
 * SimulationService.php
 * Copyright (c) 2024 james@firefly-iii.org
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

namespace FireflyIII\Services\DebtSimulation;

/**
 * Class SimulationService
 *
 * Simulates several debt payoff strategies and produces metrics and
 * schedules for each plan. Currently supports the widely used
 * "avalanche" and "snowball" approaches.
 */
class SimulationService
{
    private const STRATEGIES = ['avalanche', 'snowball'];

    /**
     * Run simulations for all strategies.
     *
     * @param array $debts         Each debt should be an associative array with keys:
     *                             name, balance, rate (annual) and min_payment.
     * @param float $monthlyBudget Total monthly amount available for debt payments.
     *
     * @return array
     */
    public function simulate(array $debts, float $monthlyBudget): array
    {
        $results = [];

        foreach (self::STRATEGIES as $strategy) {
            $plan      = $this->generateSchedule($debts, $monthlyBudget, $strategy);
            $results[] = array_merge(['strategy' => $strategy], $plan);
        }

        usort($results, static function (array $a, array $b): int {
            return [$a['total_interest'], $a['months']] <=> [$b['total_interest'], $b['months']];
        });

        $bestTotalInterest = $results[0]['total_interest'] ?? 0.0;
        foreach ($results as $i => &$result) {
            $result['rank']              = $i + 1;
            $result['cost_of_deviation'] = $result['total_interest'] - $bestTotalInterest;
        }

        return $results;
    }

    /**
     * Generate the monthly schedule for one specific strategy.
     *
     * @param array  $debts
     * @param float  $monthlyBudget
     * @param string $strategy
     *
     * @return array
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
                $payment                  = min($debt['min_payment'], $debt['balance']);
                $debt['balance']         -= $payment;
                $paymentPlan[$debt['name']] = $payment;
                $remainingBudget         -= $payment;
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
                $target['balance']         -= $extra;
                $paymentPlan[$target['name']] += $extra;
                $remainingBudget          -= $extra;
                unset($target);
            }

            $totalPayment    = $monthlyBudget - $remainingBudget;
            $totalInterest  += $interestThisMonth;
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
