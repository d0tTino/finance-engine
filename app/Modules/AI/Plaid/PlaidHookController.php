<?php

/*
 * PlaidHookController.php
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * Copyright (c) 2024
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

namespace FireflyIII\Modules\AI\Plaid;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Exceptions\BadHttpHeaderException;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class PlaidHookController
 */
class PlaidHookController extends Controller
{
    private TransactionGroupRepositoryInterface $groupRepository;

    public function __construct()
    {
        parent::__construct();
        $this->middleware(
            function ($request, $next) {
                $user                  = auth()->user();
                $userGroup             = $this->validateUserGroup($request);
                $this->groupRepository = app(TransactionGroupRepositoryInterface::class);
                $this->groupRepository->setUser($user);
                $this->groupRepository->setUserGroup($userGroup);

                return $next($request);
            }
        );
    }

    public function __invoke(Request $request): JsonResponse
    {
        $secret             = config('services.plaid.webhook_secret');
        if (null === $secret) {
            throw new NotFoundHttpException('Plaid webhook not configured.');
        }

        $this->verifySignature($request, $secret);

        $data               = $request->all();
        $data['user']       = auth()->user();
        $data['user_group'] = $this->userGroup;

        $this->groupRepository->store($data);

        return response()->json([], 201);
    }

    private function verifySignature(Request $request, string $secret): void
    {
        $header    = (string) $request->header('Plaid-Verification');
        $payload   = $request->getContent();

        parse_str(str_replace(',', '&', $header), $parts);
        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if ('' === $timestamp || '' === $signature) {
            Log::warning('Invalid Plaid verification header.');

            throw new BadHttpHeaderException('Invalid Plaid signature');
        }

        $expected  = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        if (!hash_equals($expected, $signature)) {
            Log::warning('Invalid Plaid HMAC header.');

            throw new BadHttpHeaderException('Invalid Plaid signature');
        }
    }
}
