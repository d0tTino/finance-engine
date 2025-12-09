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
    private const HIGH_APR_THRESHOLD = 0.10;

    /** @var array<string, array<int, string>> */
    private const HEURISTIC_FIELD_ORDER = [
        'interest_then_months' => ['total_interest', 'months'],
        'months_then_interest' => ['months', 'total_interest'],
    ];

    /** @var StrategyInterface[] */
    private array $strategies;
    private string $rankingHeuristic;

    /** @var array<int, string> */
    private array $rankingFields;

    public function __construct(?array $strategies = null)
    {
        $configured             = $strategies ?? config('ai.debt_simulation_strategies', []);
        $this->strategies       = [];
        $configuredHeuristic    = (string) config('ai.ranking_heuristic', self::RANKING_HEURISTIC);
        $this->rankingHeuristic = $this->resolveRankingHeuristic($configuredHeuristic);
        $this->rankingFields    = self::HEURISTIC_FIELD_ORDER[$this->rankingHeuristic];
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
     *                          `account_id`, `balance` and `apr` (APR decimal)
     *                          and may contain `id`, `name` and `min_payment`.
     * @param float $budget     Total monthly amount available for debt payments.
     * @param int   $maxOptions Maximum number of plans to return.
     *                          Converging plans are prioritised and only if
     *                          capacity remains will non-converging plans be
     *                          appended with their convergence status so the
     *                          overall total never exceeds the requested limit.
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
        $strategyClasses = array_map(
            static fn (StrategyInterface $strategy): string => get_class($strategy),
            $this->strategies
        );

        $hash     = hash('sha256', serialize([
            $accounts,
            $budget,
            $maxOptions,
            $this->rankingHeuristic,
            $strategyClasses,
        ]));

        $cacheKey = 'debt-sim-' . $hash;
        $ttl      = (int) config('ai.debt_simulation_cache_ttl', 3600);

        return UserScopedCache::remember(
            $userId,
            $groupId,
            $cacheKey,
            function () use ($accounts, $budget, $maxOptions): array {
                // Normalize account data for simulation service.
                $debts = array_map(static function (array $account): array {
                    $displayName = (string) ($account['name'] ?? $account['id'] ?? $account['account_id']);
                    $accountId   = (string) ($account['account_id'] ?? $account['id'] ?? $displayName);

                    return [
                        'account_id'   => $accountId,
                        'display_name' => $displayName,
                        'name'         => $displayName,
                        'balance'      => (float) $account['balance'],
                        'rate'         => (float) $account['apr'],
                        'min_payment'  => (float) ($account['min_payment'] ?? $account['minimum_payment'] ?? 0.0),
                    ];
                }, $accounts);

                // Baseline plan: pay only minimum payments.
                $baselineBudget   = array_sum(array_column($debts, 'min_payment'));
                $baselineInterest = null;
                if ($baselineBudget > 0) {
                    $baselinePlan = $this->generateSchedule($debts, $baselineBudget, $this->strategies[0]);
                    if ('ok' === $baselinePlan['status']) {
                        $baselineInterest = (float) $baselinePlan['total_interest'];
                    }
                }

                $convergingPlans    = [];
                $nonConvergingPlans = [];
                foreach ($this->strategies as $strategy) {
                    $plan     = $this->generateSchedule($debts, $budget, $strategy);
                    $fullPlan = array_merge(
                        [
                            'strategy' => $strategy->getName(),
                            'meta'     => ['strategy_explanation' => $strategy->getExplanation()],
                        ],
                        $plan
                    );
                    if (($fullPlan['status'] ?? 'ok') === 'non_converging') {
                        $nonConvergingPlans[] = $fullPlan;
                    } else {
                        $convergingPlans[] = $fullPlan;
                    }
                }

                usort($convergingPlans, function (array $a, array $b): int {
                    $left  = [];
                    $right = [];
                    foreach ($this->rankingFields as $field) {
                        $left[]  = $a[$field] ?? 0;
                        $right[] = $b[$field] ?? 0;
                    }

                    return $left <=> $right;

                });

                $convergingPlans = array_slice($convergingPlans, 0, $maxOptions);

                // Non-converging plans are only returned when there is remaining capacity after
                // selecting the best converging candidates. They are ranked using the same
                // heuristic but always trail the converging entries so the total never exceeds
                // the requested maximum.
                usort($nonConvergingPlans, function (array $a, array $b): int {
                    $left  = [];
                    $right = [];
                    foreach ($this->rankingFields as $field) {
                        $left[]  = $a[$field] ?? 0;
                        $right[] = $b[$field] ?? 0;
                    }

                    return $left <=> $right;
                });

                $availableSlots    = max(0, $maxOptions - count($convergingPlans));
                $nonConvergingPlans = array_slice($nonConvergingPlans, 0, $availableSlots);

                $plans = array_merge($convergingPlans, $nonConvergingPlans);

                $bestInterest = $convergingPlans[0]['total_interest'] ?? ($plans[0]['total_interest'] ?? 0.0);
                $bestMonths   = $convergingPlans[0]['months'] ?? ($plans[0]['months'] ?? 0);

                // Ensure the baseline interest is at least as high as any converging plan.
                $interestPool = [] !== $convergingPlans ? $convergingPlans : $plans;
                $maxInterest  = 0.0;
                if ([] !== $interestPool) {
                    $maxInterest = max(array_map(
                        static fn (array $p): float => (float) $p['total_interest'],
                        $interestPool
                    ));
                }
                if (null === $baselineInterest || $baselineInterest < $maxInterest) {
                    $baselineInterest = $maxInterest;
                }

                foreach ($plans as $i => &$plan) {
                    // Preserve the original status in case additional metrics overwrite keys.
                    $status = $plan['status'] ?? 'ok';

                    $plan['rank']                  = $i + 1;
                    $plan['is_optimal']            = 0 === $i && 'ok' === $status;
                    $plan['total_interest']        = (float) $plan['total_interest'];
                    $plan['interest_saved']        = $baselineInterest - $plan['total_interest'];
                    $plan['time_to_payoff_months'] = $plan['months'];
                    $plan['cost_of_deviation']     = [
                        'currency'    => $plan['total_interest'] - $bestInterest,
                        'time_months' => $plan['months'] - $bestMonths,
                    ];

                    $currencyDeviation = $plan['cost_of_deviation']['currency'];
                    $monthsDeviation   = (int) $plan['cost_of_deviation']['time_months'];

                    if ($plan['is_optimal']) {
                        $tradeoffString = 'no tradeoffs';
                        $rankingReason  = 'maximizes interest savings';
                    } else {
                        if ($monthsDeviation < 0) {
                            $tradeoffString = sprintf(
                                'loses %.2f in interest savings but %d fewer months',
                                $currencyDeviation,
                                abs($monthsDeviation)
                            );
                            $rankingReason = 'less interest saved despite faster payoff';
                        } elseif (0 === $monthsDeviation) {
                            $tradeoffString = sprintf(
                                'loses %.2f in interest savings with same payoff time',
                                $currencyDeviation
                            );
                            $rankingReason = 'less interest saved with same payoff time';
                        } else {
                            $tradeoffString = sprintf(
                                'loses %.2f in interest savings and %d more months',
                                $currencyDeviation,
                                $monthsDeviation
                            );
                            $rankingReason = 'less interest saved or longer duration';
                        }

                        if ('non_converging' === $status) {
                            $rankingReason = 'plan does not converge';
                        }
                    }

                    $heuristicScores = [];
                    foreach ($this->rankingFields as $field) {
                        $heuristicScores[$field] = $plan[$field] ?? null;
                    }

                    $tradeoffDrivers = [
                        'currency'    => [
                            'account_id'   => null,
                            'display_name' => 'Interest delta',
                            'value'        => $plan['cost_of_deviation']['currency'],
                        ],
                        'time_months' => [
                            'account_id'   => null,
                            'display_name' => 'Time delta (months)',
                            'value'        => (int) $plan['cost_of_deviation']['time_months'],
                        ],
                    ];

                    $plan['meta'] = array_merge(
                        $plan['meta'],
                        [
                            'ranking_heuristic'       => $this->rankingHeuristic,
                            'heuristic_scores'        => $heuristicScores,
                            'tradeoff_drivers'        => $tradeoffDrivers,
                            'legacy_tradeoff_drivers' => $plan['cost_of_deviation'],
                            'ranking_reason'          => $rankingReason,
                            'tradeoffs'               => $tradeoffString,
                            'accounts'                => $plan['accounts'] ?? [],
                            'account_drivers'         => $plan['account_drivers'] ?? [],
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

    private function resolveRankingHeuristic(string $heuristic): string
    {
        if (isset(self::HEURISTIC_FIELD_ORDER[$heuristic])) {
            return $heuristic;
        }

        return self::RANKING_HEURISTIC;
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
    protected function generateSchedule(array $debts, float $monthlyBudget, StrategyInterface $strategy): array
    {
        $debts = array_map(static function (array $debt): array {
            $debt['balance']      = (float) $debt['balance'];
            $debt['rate']         = (float) $debt['rate'];
            $debt['min_payment']  = (float) $debt['min_payment'];
            $debt['account_id']   = (string) $debt['account_id'];
            $debt['display_name'] = (string) ($debt['display_name'] ?? $debt['name']);

            return $debt;
        }, $debts);

        $accountsIndex      = [];
        $recommendations    = [];
        $legacyRecommendations = [];
        $accountDrivers     = [];
        $priorityContext    = $this->describeStrategyDrivers($strategy);
        foreach ($debts as $debt) {
            $accountsIndex[$debt['account_id']] = [
                'account_id'   => $debt['account_id'],
                'display_name' => $debt['display_name'],
            ];

            $accountDrivers[$debt['account_id']] = [
                'account_id'    => $debt['account_id'],
                'display_name'  => $debt['display_name'],
                'drivers'       => [
                    'apr' => [
                        'display_name' => 'APR priority',
                        'value'        => $debt['rate'],
                        'reason'       => $priorityContext['apr'],
                    ],
                    'balance' => [
                        'display_name' => 'Balance priority',
                        'value'        => $debt['balance'],
                        'reason'       => $priorityContext['balance'],
                    ],
                    'minimum_payment' => [
                        'display_name' => 'Minimum payment',
                        'value'        => $debt['min_payment'],
                        'reason'       => 'Minimum payments are made before targeting extra payments.',
                    ],
                ],
            ];

            if ($debt['rate'] >= self::HIGH_APR_THRESHOLD) {
                $message = sprintf(
                    'Consider refinancing %s to lower the %.2f%% APR.',
                    $debt['display_name'],
                    $debt['rate'] * 100
                );

                $recommendations[$debt['account_id']] = [
                    'account_id'   => $debt['account_id'],
                    'display_name' => $debt['display_name'],
                    'message'      => $message,
                ];

                $legacyRecommendations[] = $message;
            }
        }

        $schedule         = [];
        $totalInterest    = 0.0;
        $month            = 0;
        $cashFlowTimeline = [];
        $nonConverging    = false;

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

            $paymentPlan        = [];
            $legacyPaymentPlan  = [];
            $remainingBudget = $monthlyBudget;
            $annotations     = [];

            // Pay minimums first.
            foreach ($debts as &$debt) {
                if ($debt['balance'] <= 0) {
                    $accountId   = $debt['account_id'];
                    $displayName = $debt['display_name'];
                    $paymentPlan[$accountId] = [
                        'account_id'   => $accountId,
                        'display_name' => $displayName,
                        'amount'       => $paymentPlan[$accountId]['amount'] ?? 0.0,
                    ];
                    $legacyPaymentPlan[$displayName] = $legacyPaymentPlan[$displayName] ?? 0.0;
                    continue;
                }
                $payment                     = min($debt['min_payment'], $debt['balance']);
                $debt['balance']            -= $payment;
                $accountId                   = $debt['account_id'];
                $displayName                 = $debt['display_name'];
                if (!isset($paymentPlan[$accountId])) {
                    $paymentPlan[$accountId] = [
                        'account_id'   => $accountId,
                        'display_name' => $displayName,
                        'amount'       => 0.0,
                    ];
                }
                $paymentPlan[$accountId]['amount'] += $payment;
                $legacyPaymentPlan[$displayName]     = ($legacyPaymentPlan[$displayName] ?? 0.0) + $payment;
                $remainingBudget            -= $payment;
            }
            unset($debt);

            $remainingBudget = max($remainingBudget, 0.0);

            // Allocate any extra budget to targeted debt(s).
            while ($remainingBudget > 0) {
                $targetKey = $strategy->selectTargetDebt($debts);
                if (null === $targetKey) {
                    break;
                }
                $target = &$debts[$targetKey];
                $extra  = min($remainingBudget, $target['balance']);
                $accountId   = $target['account_id'];
                $displayName = $target['display_name'];
                if (!isset($paymentPlan[$accountId])) {
                    $paymentPlan[$accountId] = [
                        'account_id'   => $accountId,
                        'display_name' => $displayName,
                        'amount'       => 0.0,
                    ];
                }
                $target['balance']          -= $extra;
                $paymentPlan[$accountId]['amount'] += $extra;
                $legacyPaymentPlan[$displayName]     = ($legacyPaymentPlan[$displayName] ?? 0.0) + $extra;
                $remainingBudget            -= $extra;

                $annotations[] = [
                    'type'         => 'target_selection',
                    'account_id'   => $accountId,
                    'display_name' => $displayName,
                    'reason'       => $priorityContext['selection'],
                    'drivers'      => $accountDrivers[$accountId]['drivers'] ?? [],
                ];
                unset($target);
            }

            $totalPayment    = array_sum(array_map(static fn (array $entry): float => $entry['amount'], $paymentPlan));
            $unusedBudget    = max($remainingBudget, 0.0);
            $totalInterest  += $interestThisMonth;
            $cashFlowTimeline[] = [
                'month'         => $month,
                'cash_flow'     => $totalPayment,
                'unused_budget' => $unusedBudget,
            ];

            $balanceSnapshot       = [];
            $legacyBalanceSnapshot = [];
            $balanceAfter          = 0.0;
            foreach ($debts as $debt) {
                $currentBalance                   = max($debt['balance'], 0.0);
                $balanceSnapshot[$debt['account_id']] = [
                    'account_id'   => $debt['account_id'],
                    'display_name' => $debt['display_name'],
                    'balance'      => $currentBalance,
                ];
                $legacyBalanceSnapshot[$debt['display_name']] = $currentBalance;
                $balanceAfter                    += $currentBalance;
            }

            $schedule[] = [
                'month'         => $month,
                'payments'      => $paymentPlan,
                'balances'      => $balanceSnapshot,
                'payments_legacy' => $legacyPaymentPlan,
                'balances_legacy' => $legacyBalanceSnapshot,
                'interest'      => $interestThisMonth,
                'payment'       => $totalPayment,
                'cash_flow'     => $totalPayment,
                'unused_budget' => $unusedBudget,
                'annotations'   => $annotations,
            ];

            if ($balanceAfter > $balanceBefore) {
                $nonConverging = true;
                break;
            }
        }

        return [
            'accounts'          => array_values($accountsIndex),
            'account_drivers'   => array_values($accountDrivers),
            'schedule'          => $schedule,
            'total_interest'    => $totalInterest,
            'months'            => $month,
            'monthly_cash_flow' => $cashFlowTimeline,
            'recommendations'   => $recommendations,
            'legacy_recommendations' => $legacyRecommendations,
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

    /**
     * Provide human-friendly context for how a strategy chooses targets.
     *
     * @return array{apr: string, balance: string, selection: string}
     */
    private function describeStrategyDrivers(StrategyInterface $strategy): array
    {
        $name = $strategy->getName();

        return match ($name) {
            'avalanche' => [
                'apr'       => 'Extra payments focus on the highest APR first to cut interest costs.',
                'balance'   => 'Balances are considered after APR when selecting targets.',
                'selection' => 'Chosen because this debt currently has the highest APR.',
            ],
            'snowball' => [
                'apr'       => 'APR is secondary; smallest balances are targeted to gain quick wins.',
                'balance'   => 'Extra payments prioritize the smallest balance to clear debts quickly.',
                'selection' => 'Chosen because this debt has one of the smallest remaining balances.',
            ],
            'balanced' => [
                'apr'       => 'APR does not change the proportional distribution but higher APR still accrues more interest.',
                'balance'   => 'Extra payments are distributed proportionally to each remaining balance.',
                'selection' => 'Chosen as part of proportional balance-based distribution.',
            ],
            'ml' => [
                'apr'       => 'APR informs the model and the fallback avalanche ordering.',
                'balance'   => 'Balances inform the model and fallback ordering when predictions are unavailable.',
                'selection' => 'Chosen based on the machine learning ranking or avalanche fallback.',
            ],
            default => [
                'apr'       => 'APR influences overall interest but may not directly control targeting.',
                'balance'   => 'Balances inform how remaining debts are prioritized.',
                'selection' => 'Chosen according to the strategy targeting rules.',
            ],
        };
    }

}

