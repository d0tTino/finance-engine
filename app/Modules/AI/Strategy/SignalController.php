<?php

/*
 * SignalController.php
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

namespace FireflyIII\Modules\AI\Strategy;

use FireflyIII\Api\V1\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Class SignalController
 *
 * Receives trading signals from AI modules and forwards them to brokers.
 */
class SignalController extends Controller
{
    private BrokerSdk $broker;

    public function __construct()
    {
        parent::__construct();
        $this->broker = app(BrokerSdk::class);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset'      => 'required|string',
            'action'     => 'required|in:buy,sell',
            'confidence' => 'required|numeric|between:0,1',
        ]);

        $this->broker->sendSignal($validated);

        return response()->json([], 202);
    }
}
