<?php

/*
 * ProcessPlaidTransactions.php
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

namespace FireflyIII\Jobs;

use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Class ProcessPlaidTransactions
 */
class ProcessPlaidTransactions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(private array $payload) {}

    /**
     * Execute the job.
     */
    public function handle(TransactionGroupRepositoryInterface $groupRepository): void
    {
        $user        = User::find((int) ($this->payload['user_id'] ?? 0));
        $userGroup   = UserGroup::find((int) ($this->payload['user_group_id'] ?? 0));
        $transactions = $this->payload['transactions'] ?? [];

        if (!$user instanceof User || !$userGroup instanceof UserGroup || !is_array($transactions)) {
            Log::warning('Invalid data for Plaid transaction processing.');

            return;
        }

        $groupRepository->setUser($user);
        $groupRepository->setUserGroup($userGroup);

        $data = $this->payload;
        $data['user']       = $user;
        $data['user_group'] = $userGroup;

        $start = microtime(true);
        $groupRepository->store($data);
        $duration   = microtime(true) - $start;
        $count      = count($transactions);
        $throughput = $duration > 0 ? $count / $duration : $count;

        Log::info(sprintf('Processed %d Plaid transactions at %.2f tx/sec', $count, $throughput));
        if ($throughput < 5) {
            Log::warning(sprintf('Plaid throughput below target: %.2f tx/sec', $throughput));
        }
    }
}

