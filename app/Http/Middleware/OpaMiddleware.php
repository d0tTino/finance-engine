<?php

/**
 * OpaMiddleware.php
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

namespace FireflyIII\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Class OpaMiddleware
 */
class OpaMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        try {
            $response = Http::post('http://localhost:8181/v1/data/finance/authz', [
                'input' => [
                    'method' => $request->getMethod(),
                    'path'   => $request->path(),
                ],
            ]);
            $data    = $response->json();
            $allowed = $data['result']['allow'] ?? $data['result'] ?? false;
        } catch (\Throwable $e) {
            $allowed = false;
        }

        if ($allowed) {
            return $next($request);
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }
}
