<?php

declare(strict_types=1);

namespace Tests\feature\Api\V1\Simulations;

use FireflyIII\Console\Commands\Correction\CreatesGroupMemberships;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Http\Middleware\OpaMiddleware;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
use FireflyIII\Modules\AI\Simulations\Strategies\StrategyInterface;
use FireflyIII\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;
use Tests\integration\TestCase;
use function Safe\putenv;

final class DebtControllerTest extends TestCase
{
    public function testDebtSimulationResponseMatchesContract(): void
    {
        Cache::setDefaultDriver('array');
        putenv('CACHE_DRIVER=array');
        Cache::flush();

        $this->withoutMiddleware([EnsureFrontendRequestsAreStateful::class, OpaMiddleware::class]);

        $this->ensureUuidColumns();

        app()->singleton(DeterministicStrategy::class, static fn (): DeterministicStrategy => new DeterministicStrategy());
        app()->bind(DebtSimulationService::class, static fn (): DebtSimulationService => new DebtSimulationService([DeterministicStrategy::class]));
        config([
            'ai.debt_simulation_strategies' => [DeterministicStrategy::class],
            'ai.ranking_heuristic'          => DebtSimulationService::RANKING_HEURISTIC,
            'auth.guards.api'               => ['driver' => 'token', 'provider' => 'users'],
        ]);

        $user = User::create([
            'email'    => 'feature-user@example.com',
            'password' => 'secret',
        ]);
        CreatesGroupMemberships::createGroupMembership($user);
        $user->refresh();
        if (null === $user->uuid) {
            $user->uuid = (string) Str::uuid();
            $user->save();
            $user->refresh();
        }
        Sanctum::actingAs($user);

        $debtType = AccountType::where('type', AccountTypeEnum::DEBT->value)->firstOrFail();

        $debt = Account::create([
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'account_type_id' => $debtType->id,
            'name'            => 'Primary Debt',
            'active'          => true,
        ]);
        $debt->refresh();
        if (null === $debt->uuid) {
            $debt->uuid = (string) Str::uuid();
            $debt->save();
            $debt->refresh();
        }

        $payload = [
            'user_id'        => (string) $user->uuid,
            'group_id'       => null,
            'accounts'       => [[
                'account_id'      => (string) $debt->uuid,
                'balance'         => 500.0,
                'apr'             => 0.05,
                'minimum_payment' => 25.0,
            ]],
            'monthly_budget' => 75.0,
            'max_options'    => 1,
        ];

        $response = $this->postJson('/api/v1/simulations/debt', $payload)->assertOk();

        $response->assertJsonStructure([
            'analysis_id',
            'proposed_actions' => [[
                'rank',
                'is_optimal',
                'plan' => [
                    'strategy',
                    'accounts',
                    'schedule',
                    'legacy_schedule',
                    'recommendations',
                    'legacy_recommendations',
                    'status',
                ],
                'metrics' => [
                    'interest_saved',
                    'time_to_payoff_months',
                    'total_interest_paid',
                    'monthly_cash_flow',
                ],
                'meta' => [
                    'ranking_heuristic',
                    'tradeoffs',
                    'tradeoff_drivers',
                    'legacy_tradeoff_drivers',
                    'heuristic_scores',
                    'strategy_explanation',
                    'ranking_reason',
                    'accounts',
                ],
            ]],
        ]);

        self::assertTrue(Str::isUuid($response->json('analysis_id')));

        $action = $response->json('proposed_actions.0');
        self::assertIsArray($action);
        self::assertSame(1, $action['rank']);
        self::assertTrue((bool) $action['is_optimal']);
        self::assertIsArray($action['plan']['schedule']);
        self::assertNotEmpty($action['plan']['schedule']);
        self::assertIsArray($action['plan']['legacy_schedule']);
        self::assertSameSize($action['plan']['schedule'], $action['plan']['legacy_schedule']);
        self::assertIsString($action['plan']['status']);
        self::assertIsArray($action['metrics']['monthly_cash_flow']);
        self::assertIsArray($action['meta']['tradeoff_drivers']);
        self::assertIsArray($action['meta']['legacy_tradeoff_drivers']);
        self::assertIsArray($action['meta']['accounts']);
        self::assertIsString($action['meta']['tradeoffs']);
    }

    private function ensureUuidColumns(): void
    {
        if (!Schema::hasColumn('users', 'uuid')) {
            Schema::table('users', static function (Blueprint $table): void {
                $table->uuid('uuid')->nullable();
            });
        }

        if (!Schema::hasColumn('accounts', 'uuid')) {
            Schema::table('accounts', static function (Blueprint $table): void {
                $table->uuid('uuid')->nullable();
            });
        }
    }
}

final class DeterministicStrategy implements StrategyInterface
{
    public function getName(): string
    {
        return 'deterministic';
    }

    public function getExplanation(): string
    {
        return 'Allocates extra payments to the first open balance.';
    }

    public function reset(): void
    {
        // No internal state to reset.
    }

    public function selectTargetDebt(array $debts): ?int
    {
        foreach ($debts as $index => $debt) {
            if (($debt['balance'] ?? 0.0) > 0.0) {
                return $index;
            }
        }

        return null;
    }
}
