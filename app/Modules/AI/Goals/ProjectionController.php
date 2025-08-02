<?php

/*
 * ProjectionController.php
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

namespace FireflyIII\Modules\AI\Goals;

use FireflyIII\Api\V1\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Class ProjectionController
 *
 * Returns a Monte-Carlo projection for a goal.
 */
class ProjectionController extends Controller
{
    private MonteCarloSimulationService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = app(MonteCarloSimulationService::class);
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $initial    = (float) $request->get('initial', 0);
        $mean       = (float) $request->get('mean', 0.05);
        $stdev      = (float) $request->get('stdev', 0.02);
        $years      = (int) $request->get('years', 5);

        $result = $this->service->projectJson($initial, $mean, $stdev, $years);

        return response()->json($result);
    }
}
