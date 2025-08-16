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

use FireflyIII\Modules\AI\Simulations\Strategies\StrategyInterface;
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
    public const MAX_MONTHS = 600;

    /** @var StrategyInterface[] */
    private array $strategies;

    public function __construct(?array $strategies = null)
    {
        $configured = $strategies ?? config('ai.debt_simulation_strategies', []);
        $this->strategies = [];
        foreach ($configured as $strategyClass) {
            $instance = app($strategyClass);
            if ($instance instanceof StrategyInterface) {
                $this->strategies[] = $instance;
            }
        }
    }

    /**
     * Run simulations for all strategies.
     *
     * @param string      $userId     The owning user identifier.
     * @param string|null $groupId    The user group identifier.
     * @param array $accounts   Array of accounts. Each entry must contain
     *                          `account_id`, `balance` and `apr` (APR percentage)
     *                          and may contain `id`, `name` and `min_payment`.
     * @param float $budget     Total monthly amount available for debt payments.
     * @param int   $maxOptions Maximum number of plans to return.
     *
     * @return array<int, array<string, mixed>>
     */
    public function simulate(string $userId, ?string $groupId, array $accounts, float $budget, int $maxOptions = 2): array
    {
        if (0 === count($this->strategies)) {
            return [];
        }

        usort($accounts, static function (array $a, array $b): int {
            return ($a['account_id'] ?? 0) <=> ($b['account_id'] ?? 0);
        });

        $hash     = hash('sha256', serialize([$accounts, $budget, $maxOptions]));

        $cacheKey = 'debt-sim-' . $hash;
        $ttl      = (int) config('ai.debt_simulation_cache_ttl', 3600);

        return UserScopedCache::remember(
            $userId,
            $groupId,
            $cacheKey,
            function () use ($accounts, $budget, $maxOptions): array {
                // Normalize account data for simulation service.
                $debts = array_map(static function (array $account): array {
                    return [
                        'name'        => (string) ($account['name'] ?? $account['id'] ?? $account['account_id']),
                        'balance'     => (float) $account['balance'],
                        'rate'        => (float) $account['apr'] / 100,
                        'min_payment' => (float) ($account['min_payment'] ?? $account['minimum_payment'] ?? 0.0),
                    ];
                }, $accounts);

                $plans = [];
                foreach ($this->strategies as $strategy) {
                    $plan    = $this->generateSchedule($debts, $budget, $strategy);
                    $plans[] = array_merge(
                        [
                            'strategy' => $strategy->getName(),
                            'meta'     => ['strategy_explanation' => $strategy->getDescription()],
                        ],
                        $plan
                    );
                }

                usort($plans, static function (array $a, array $b): int {
                    return [$a['total_interest'], $a['months']] <=> [$b['total_interest'], $b['months']];
                });

                $plans = array_slice($plans, 0, $maxOptions);

                $bestInterest  = $plans[0]['total_interest'] ?? 0.0;
                $bestMonths    = $plans[0]['months'] ?? 0;
                $interestList  = array_column($plans, 'total_interest');
                $worstInterest = $interestList !== [] ? max($interestList) : 0.0;

                foreach ($plans as $i => &$plan) {
                    // Preserve the original status in case additional metrics overwrite keys.
                    $status = $plan['status'] ?? 'ok';

                    $plan['rank']                  = $i + 1;
                    $plan['is_optimal']            = 0 === $i;
                    $plan['total_interest']        = (float) $plan['total_interest'];
                    $plan['interest_saved']        = $worstInterest - $plan['total_interest'];
                    $plan['time_to_payoff_months'] = $plan['months'];
                    $plan['cost_of_deviation']     = [
                        'currency'    => $plan['total_interest'] - $bestInterest,
                        'time_months' => $plan['months'] - $bestMonths,
                    ];

                    $tradeoffString = $plan['is_optimal']
                        ? 'no tradeoffs'
                        : sprintf(
                            '%.2f extra interest and %d more months',
                            $plan['cost_of_deviation']['currency'],
                            $plan['cost_of_deviation']['time_months']
                        );

                    $plan['meta'] = array_merge(
                        $plan['meta'],
                        [
                            'ranking_heuristic' => self::RANKING_HEURISTIC,
                            'ranking_reason'    => $plan['is_optimal'] ? 'minimizes interest' : 'higher cost or duration',
                            'tradeoffs'         => $tradeoffString,
                        ]
                    );

                    // Re-attach the status so consumers can understand convergence state.
                    $plan['status'] = $status;
                }
                unset($plan);

                return $plans;
            },
            $ttl
        );
    }

    /**
     * Generate the monthly schedule for one specific strategy.
     *
     * @param array  $debts
     * @param float  $monthlyBudget
     * @param StrategyInterface $strategy
     *
     * @return array<string, mixed>
     */
    private function generateSchedule(array $debts, float $monthlyBudget, StrategyInterface $strategy): array
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
        $nonConverging     = false;

        $strategy->reset();

        while ($this->hasBalance($debts)) {
            if ($month >= self::MAX_MONTHS) {
                $nonConverging = true;
                break;
            }

            ++$month;
            $interestThisMonth = 0.0;
            $balanceBefore     = 0.0;
            foreach ($debts as $debt) {
                $balanceBefore += max($debt['balance'], 0.0);
            }
            unset($debt);
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

            $remainingBudget = max($remainingBudget, 0.0);

            // Allocate any extra budget to targeted debt(s).
            while ($remainingBudget > 0 && $this->hasBalance($debts)) {
                $targetKey = $strategy->selectTargetDebt($debts);
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

            $totalPayment      = array_sum($paymentPlan);
            $totalInterest    += $interestThisMonth;
            $cashFlowTimeline[] = ['month' => $month, 'cash_flow' => $remainingBudget];

            $balanceSnapshot = [];
            $balanceAfter    = 0.0;
            foreach ($debts as $debt) {
                $currentBalance                   = max($debt['balance'], 0.0);
                $balanceSnapshot[$debt['name']]   = $currentBalance;
                $balanceAfter                    += $currentBalance;
            }

            $schedule[] = [
                'month'     => $month,
                'payments'  => $paymentPlan,
                'balances'  => $balanceSnapshot,
                'interest'  => $interestThisMonth,
                'payment'   => $totalPayment,
                'cash_flow' => $remainingBudget,
            ];

            if ($balanceAfter > $balanceBefore) {
                $nonConverging = true;
                break;
            }
        }

        return [
            'schedule'          => $schedule,
            'total_interest'    => $totalInterest,
            'months'            => $month,
            'monthly_cash_flow' => $cashFlowTimeline,
            'status'            => $nonConverging ? 'non_converging' : 'ok',
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

}

