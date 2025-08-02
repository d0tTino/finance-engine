<?php

/*
 * BrokerSdk.php
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

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Class BrokerSdk
 *
 * Sends trading signals to a configured broker implementation.
 */
class BrokerSdk
{
    /**
     * Forward the given signal to the configured broker.
     */
    public function sendSignal(array $payload): void
    {
        $driver = config('services.broker.driver');
        $url    = match ($driver) {
            'alpaca'    => config('services.broker.alpaca_url'),
            'freqtrade' => config('services.broker.freqtrade_url'),
            default     => null,
        };

        if (null === $url) {
            Log::warning('No broker configured for trading signals.');

            return;
        }

        try {
            Http::post($url, $payload);
        } catch (Throwable $e) {
            Log::error(sprintf('Failed to forward signal: %s', $e->getMessage()));
        }
    }
}
