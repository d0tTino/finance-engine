<?php

/*
 * DebtController.php
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

namespace FireflyIII\Api\V1\Controllers\Simulations;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Api\V1\Requests\Simulations\DebtRequest;
use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
use FireflyIII\Enums\UserRoleEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Class DebtController
 *
 * Endpoint that returns ranked payoff plans for outstanding debts.
 */
class DebtController extends Controller
{
    /** @var array<int, UserRoleEnum> */
    protected array $acceptedRoles = [UserRoleEnum::READ_ONLY];

    private DebtSimulationService $service;

    public function __construct(DebtSimulationService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function __invoke(DebtRequest $request): JsonResponse
    {
        $data       = $request->getData();
        $plans      = $this->service->simulate(
            $data['user_id'],
            $data['group_id'],

            $data['accounts'],
            (float) $data['monthly_budget'],
            (int) $data['max_options']
        );

        $analysisId      = (string) Str::uuid();
        $proposedActions = array_map(
            static function (array $plan): array {
                $costOfDeviation = self::serializeCostOfDeviation($plan);
                $planForMeta     = $plan;
                $planForMeta['cost_of_deviation'] = $costOfDeviation;

                $schedule = array_map(
                    static function (array $entry): array {
                        return [
                            'month'           => $entry['month'],
                            'payments'        => $entry['payments'],
                            'balances'        => $entry['balances'],
                            'payments_legacy' => $entry['payments_legacy'] ?? [],
                            'balances_legacy' => $entry['balances_legacy'] ?? [],
                            'interest'        => $entry['interest'],
                            'payment'         => $entry['payment'],
                            'cash_flow'       => $entry['cash_flow'],
                            'unused_budget'   => $entry['unused_budget'],
                        ];
                    },
                    $plan['schedule']
                );

                $legacySchedule = array_map(
                    static function (array $entry): array {
                        return [
                            'month'     => $entry['month'],
                            'payments'  => $entry['payments_legacy'] ?? [],
                            'balances'  => $entry['balances_legacy'] ?? [],
                            'interest'  => $entry['interest'],
                            'payment'   => $entry['payment'],
                            'cash_flow' => $entry['cash_flow'],
                            'unused_budget' => $entry['unused_budget'],
                        ];
                    },
                    $plan['schedule']
                );

                $result = [
                    'rank'       => $plan['rank'],
                    'is_optimal' => $plan['is_optimal'],
                    'plan'       => [
                        'strategy'               => $plan['strategy'],
                        'accounts'               => $plan['accounts'] ?? [],
                        'schedule'               => $schedule,
                        'legacy_schedule'        => $legacySchedule,
                        'recommendations'        => $plan['recommendations'] ?? [],
                        'legacy_recommendations' => $plan['legacy_recommendations'] ?? [],
                        'status'                 => $plan['status'],
                    ],
                    'metrics' => [
                        'interest_saved'        => (float) $plan['interest_saved'],
                        'time_to_payoff_months' => (int) $plan['time_to_payoff_months'],
                        'total_interest_paid'   => (float) $plan['total_interest'],
                        'monthly_cash_flow'     => $plan['monthly_cash_flow'],
                    ],
                    'cost_of_deviation' => $costOfDeviation,
                ];

                $result['meta'] = [
                    'ranking_heuristic'       => $plan['meta']['ranking_heuristic'],
                    'ranking_reason'          => $plan['meta']['ranking_reason'] ?? $plan['meta']['ranking_heuristic'],
                    'tradeoffs'               => self::describeTradeoffs($planForMeta),
                    'tradeoff_drivers'        => $plan['meta']['tradeoff_drivers'] ?? [],
                    'legacy_tradeoff_drivers' => $plan['meta']['legacy_tradeoff_drivers'] ?? $costOfDeviation,
                    'heuristic_scores'        => $plan['meta']['heuristic_scores'] ?? [],
                    'strategy_explanation'    => $plan['meta']['strategy_explanation'],
                    'accounts'                => $plan['meta']['accounts'] ?? ($plan['accounts'] ?? []),
                ];

                return $result;
            },
            $plans
        );

        return response()->json([
            'analysis_id'      => $analysisId,
            'proposed_actions' => $proposedActions,
        ]);
    }

    /**
     * Normalize the simulation-provided cost-of-deviation structure.
     *
     * @param array<string, mixed> $plan
     *
     * @return array{currency: float, time_months: int}
     */
    private static function serializeCostOfDeviation(array $plan): array
    {
        $raw = $plan['cost_of_deviation'] ?? null;

        $currency = 0.0;
        $time     = 0;

        if (is_array($raw)) {
            if (array_key_exists('currency', $raw) && is_numeric($raw['currency'])) {
                $currency = (float) $raw['currency'];
            }

            if (array_key_exists('time_months', $raw) && is_numeric($raw['time_months'])) {
                $time = (int) $raw['time_months'];
            }
        }

        return [
            'currency'    => $currency,
            'time_months' => $time,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function describeTradeoffs(array $plan): string
    {
        $tradeoffs = $plan['meta']['tradeoffs'] ?? null;
        if (is_string($tradeoffs) && trim($tradeoffs) !== '') {
            return $tradeoffs;
        }

        $costOfDeviation = $plan['cost_of_deviation'] ?? null;
        if (!is_array($costOfDeviation)) {
            return 'tradeoff impact unavailable';
        }

        $currencyValue = $costOfDeviation['currency'] ?? null;
        $timeValue     = $costOfDeviation['time_months'] ?? null;

        $hasCurrency = is_numeric($currencyValue);
        $hasTime     = is_numeric($timeValue);

        if (!$hasCurrency && !$hasTime) {
            return 'tradeoff impact unavailable';
        }

        $currencyDescription = 'interest impact unavailable';
        if ($hasCurrency) {
            $currencyFloat = (float) $currencyValue;
            if (0.0 === $currencyFloat) {
                $currencyDescription = 'no interest impact';
            } elseif ($currencyFloat > 0.0) {
                $currencyDescription = sprintf('loses %s in interest savings', number_format(abs($currencyFloat), 2, '.', ''));
            } else {
                $currencyDescription = sprintf('saves %s more interest', number_format(abs($currencyFloat), 2, '.', ''));
            }
        }

        $timeDescription = 'time impact unavailable';
        if ($hasTime) {
            $timeInteger = (int) round((float) $timeValue);
            if (0 === $timeInteger) {
                $timeDescription = 'no time impact';
            } else {
                $absMonths = abs($timeInteger);
                $label     = 1 === $absMonths ? 'month' : 'months';
                $timeDescription = $timeInteger > 0
                    ? sprintf('%d extra %s', $absMonths, $label)
                    : sprintf('%d fewer %s', $absMonths, $label);
            }
        }

        if ('no interest impact' === $currencyDescription && 'no time impact' === $timeDescription) {
            return 'no tradeoffs';
        }

        return sprintf('%s and %s', $currencyDescription, $timeDescription);
    }
}
