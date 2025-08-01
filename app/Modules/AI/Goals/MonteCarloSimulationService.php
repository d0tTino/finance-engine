<?php

/*
 * MonteCarloSimulationService.php
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

/**
 * Class MonteCarloSimulationService
 *
 * Performs a simple Monte-Carlo simulation to project goal growth over time.
 */
class MonteCarloSimulationService
{
    private function randomNormal(float $mean = 0.0, float $stdev = 1.0): float
    {
        // Box-Muller transform
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();
        $z0 = \sqrt(-2.0 * \log($u1)) * \cos(2 * M_PI * $u2);

        return $z0 * $stdev + $mean;
    }

    /**
     * Run the simulation.
     *
     * @return array<int, array<string, float|int>>
     */
    public function project(float $initial, float $mean, float $stdev, int $years, int $runs = 1000): array
    {
        $steps      = $years * 12; // monthly steps
        $projection = [];

        for ($step = 1; $step <= $steps; ++$step) {
            $total        = 0.0;
            for ($run = 0; $run < $runs; ++$run) {
                $value = $initial;
                for ($i = 0; $i < $step; ++$i) {
                    $value *= 1 + $this->randomNormal($mean / 12, $stdev / \sqrt(12));
                }
                $total += $value;
            }
            $average      = $total / $runs;
            $projection[] = [
                'month'  => $step,
                'amount' => $average,
            ];
        }

        return $projection;
    }
}
