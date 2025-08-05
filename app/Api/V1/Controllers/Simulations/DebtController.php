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
        $data  = $request->getData();
        $plans = $this->service->simulate(
            (string) $data['user_id'],
            isset($data['group_id']) ? (string) $data['group_id'] : null,
            $data['accounts'],
            (float) $data['monthly_budget'],
            (int) $data['max_options']
        );

        return response()->json([
            'data' => $plans,
            'meta' => ['ranking_heuristic' => DebtSimulationService::RANKING_HEURISTIC],
        ]);
    }
}
