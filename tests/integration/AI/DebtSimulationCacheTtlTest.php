<?php

declare(strict_types=1);

namespace Tests\integration\AI;

use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
use Illuminate\Support\Facades\Cache;
use Tests\integration\TestCase;

/**
 * @group integration-test
 * @group ai
 *
 * @internal
 */
final class DebtSimulationCacheTtlTest extends TestCase
{
    public function testCacheExpiresAndRegenerates(): void
    {
        Cache::flush();
        config(['ai.debt_simulation_cache_ttl' => 1]);

        $service  = new DebtSimulationService();
        $userId   = '1';
        $groupId  = '1';
        $accounts = [
            ['account_id' => 1, 'balance' => 100.0, 'apr' => 5.0],
        ];
        $budget     = 50.0;
        $maxOptions = 2;

        $service->simulate($userId, $groupId, $accounts, $budget, $maxOptions);

        $normalizedAccounts = $accounts;
        usort($normalizedAccounts, static function (array $a, array $b): int {
            return ($a['account_id'] ?? 0) <=> ($b['account_id'] ?? 0);
        });
        $heuristic = (string) config('ai.ranking_heuristic', DebtSimulationService::RANKING_HEURISTIC);
        $strategies = array_map(
            static fn (string $strategyClass): string => get_class(app($strategyClass)),
            config('ai.debt_simulation_strategies', [])
        );
        $hash     = hash('sha256', serialize([$normalizedAccounts, $budget, $maxOptions, $heuristic, $strategies]));
        $cacheKey = sprintf('u:%s:g:%s:debt-sim-%s', $userId, $groupId, $hash);
        self::assertTrue(Cache::has($cacheKey));

        sleep(2);
        self::assertFalse(Cache::has($cacheKey));

        $service->simulate($userId, $groupId, $accounts, $budget, $maxOptions);
        self::assertTrue(Cache::has($cacheKey));
    }
}
