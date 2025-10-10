<?php

declare(strict_types=1);

namespace Tests\api\v1\Simulations;

use FireflyIII\Console\Commands\Correction\CreatesGroupMemberships;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Http\Middleware\Authenticate;
use FireflyIII\Http\Middleware\OpaMiddleware;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\integration\TestCase;
use function Safe\putenv;

/**
 * Tests debt simulation API cross-user access handling and response structure.
 *
 * @internal
 */
final class DebtSimulationTest extends TestCase
{
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

    public function testRejectsCrossUserAccountAccess(): void
    {
        Cache::setDefaultDriver('file');
        putenv('CACHE_DRIVER=file');
        Cache::flush();
        $this->withoutMiddleware([Authenticate::class, 'auth:api', 'auth:api,sanctum', EnsureFrontendRequestsAreStateful::class, OpaMiddleware::class]);
        config(['auth.defaults.guard' => 'web']);

        $this->ensureUuidColumns();

        $user1 = User::create(['email' => 'user1@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user1);
        $user1->refresh();
        if ($user1->uuid === null) {
            $user1->uuid = (string) Str::uuid();
            $user1->save();
            $user1->refresh();
        }
        $this->be($user1);

        $user2 = User::create(['email' => 'user2@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user2);
        $user2->refresh();
        if ($user2->uuid === null) {
            $user2->uuid = (string) Str::uuid();
            $user2->save();
            $user2->refresh();
        }

        $type    = AccountType::where('type', AccountTypeEnum::DEBT->value)->first();
        $foreign = Account::create([
            'user_id'         => $user2->id,
            'user_group_id'   => $user2->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Foreign debt',
            'active'          => true,
        ]);
        $foreign->refresh();
        if ($foreign->uuid === null) {
            $foreign->uuid = (string) Str::uuid();
            $foreign->save();
            $foreign->refresh();
        }

        $payload = [
            'user_id'        => (string) $user1->uuid,
            'group_id'       => null,
            'accounts'       => [[
                'account_id'       => (string) $foreign->uuid,
                'balance'          => 100.0,
                'apr'              => 5.0,
                'minimum_payment'  => 0.0,
            ]],
            'monthly_budget' => 50.0,
            'max_options'    => 2,
        ];

        $this->postJson('/api/v1/simulations/debt', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accounts.0.account_id']);
    }

    public function testReturnsStructuredResponse(): void
    {
        Cache::setDefaultDriver('file');
        putenv('CACHE_DRIVER=file');
        Cache::flush();
        $this->withoutMiddleware([Authenticate::class, 'auth:api', 'auth:api,sanctum', EnsureFrontendRequestsAreStateful::class, OpaMiddleware::class]);
        config(['auth.defaults.guard' => 'web']);

        $this->ensureUuidColumns();

        $budget     = 50.0;
        $maxOptions = 2;

        $user = User::create(['email' => 'user@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user);
        $user->refresh();
        if ($user->uuid === null) {
            $user->uuid = (string) Str::uuid();
            $user->save();
            $user->refresh();
        }
        $this->be($user);

        $type = AccountType::where('type', AccountTypeEnum::DEBT->value)->first();
        $a1   = Account::create([
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Debt 1',
            'active'          => true,
        ]);
        $a1->refresh();
        if ($a1->uuid === null) {
            $a1->uuid = (string) Str::uuid();
            $a1->save();
            $a1->refresh();
        }
        $a2 = Account::create([
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Debt 2',
            'active'          => true,
        ]);
        $a2->refresh();
        if ($a2->uuid === null) {
            $a2->uuid = (string) Str::uuid();
            $a2->save();
            $a2->refresh();
        }

        $accounts = [
            [
                'account_id'      => (string) $a1->uuid,
                'balance'         => 100.0,
                'apr'             => 5.0,
                'minimum_payment' => 10.0,
            ],
            [
                'account_id'      => (string) $a2->uuid,
                'balance'         => 200.0,
                'apr'             => 3.0,
                'minimum_payment' => 10.0,
            ],
        ];

        $payload = [
            'user_id'        => (string) $user->uuid,
            'group_id'       => null,
            'accounts'       => $accounts,
            'monthly_budget' => $budget,
            'max_options'    => $maxOptions,
        ];

        $response = $this->postJson('/api/v1/simulations/debt', $payload)->assertOk();
        $response->assertJsonStructure([
            'analysis_id',
            'proposed_actions' => [
                [
                    'rank',
                    'is_optimal',
                    'plan'    => ['strategy', 'accounts', 'schedule', 'legacy_schedule', 'recommendations', 'legacy_recommendations', 'status'],
                    'metrics' => [
                        'interest_saved',
                        'time_to_payoff_months',
                        'total_interest_paid',
                        'monthly_cash_flow',
                    ],
                    'meta' => ['ranking_heuristic', 'tradeoffs', 'ranking_reason', 'strategy_explanation', 'tradeoff_drivers', 'legacy_tradeoff_drivers', 'accounts'],
                ],
            ],
        ]);

        self::assertTrue(Str::isUuid($response->json('analysis_id')));
        $actions = $response->json('proposed_actions');
        foreach ($actions as $action) {
            self::assertGreaterThanOrEqual(0.0, $action['metrics']['interest_saved']);
            self::assertIsArray($action['plan']['accounts']);
            $firstSchedule = $action['plan']['schedule'][0];
            self::assertArrayHasKey('payments', $firstSchedule);
            self::assertArrayHasKey('balances', $firstSchedule);
            self::assertArrayHasKey('payments_legacy', $firstSchedule);
            self::assertArrayHasKey('balances_legacy', $firstSchedule);
            $legacySchedule = $action['plan']['legacy_schedule'][0];
            self::assertIsArray($legacySchedule['payments']);
            self::assertIsArray($legacySchedule['balances']);
            self::assertSame($firstSchedule['payment'], $legacySchedule['payment']);
            self::assertIsArray($action['plan']['recommendations']);
            self::assertIsArray($action['plan']['legacy_recommendations']);
            self::assertArrayHasKey('currency', $action['meta']['tradeoff_drivers']);
            self::assertArrayHasKey('value', $action['meta']['tradeoff_drivers']['currency']);
            self::assertArrayHasKey('time_months', $action['meta']['tradeoff_drivers']);
            self::assertArrayHasKey('value', $action['meta']['tradeoff_drivers']['time_months']);
        }
        self::assertGreaterThanOrEqual(0.0, $actions[0]['metrics']['interest_saved']);
        self::assertSame(1, $actions[0]['rank']);
        self::assertArrayHasKey('strategy_explanation', $actions[0]['meta']);
    }

    public function testNonConvergingPlanIsNotOptimal(): void
    {
        Cache::setDefaultDriver('file');
        putenv('CACHE_DRIVER=file');
        Cache::flush();
        $this->withoutMiddleware([Authenticate::class, 'auth:api', 'auth:api,sanctum', EnsureFrontendRequestsAreStateful::class, OpaMiddleware::class]);
        config(['auth.defaults.guard' => 'web']);

        $this->ensureUuidColumns();

        $user = User::create(['email' => 'user@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user);
        $user->refresh();
        if ($user->uuid === null) {
            $user->uuid = (string) Str::uuid();
            $user->save();
            $user->refresh();
        }
        $this->be($user);

        $type = AccountType::where('type', AccountTypeEnum::DEBT->value)->first();
        $a1   = Account::create([
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'High APR',
            'active'          => true,
        ]);
        $a1->refresh();
        if ($a1->uuid === null) {
            $a1->uuid = (string) Str::uuid();
            $a1->save();
            $a1->refresh();
        }
        $a2 = Account::create([
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Low APR',
            'active'          => true,
        ]);
        $a2->refresh();
        if ($a2->uuid === null) {
            $a2->uuid = (string) Str::uuid();
            $a2->save();
            $a2->refresh();
        }

        $accounts = [
            [
                'account_id'      => (string) $a1->uuid,
                'balance'         => 1000.0,
                'apr'             => 120.0,
                'minimum_payment' => 0.0,
            ],
            [
                'account_id'      => (string) $a2->uuid,
                'balance'         => 100.0,
                'apr'             => 0.0,
                'minimum_payment' => 0.0,
            ],
        ];

        $payload = [
            'user_id'        => (string) $user->uuid,
            'group_id'       => null,
            'accounts'       => $accounts,
            'monthly_budget' => 150.0,
            'max_options'    => 3,
        ];

        $response = $this->postJson('/api/v1/simulations/debt', $payload)->assertOk();

        $actions = $response->json('proposed_actions');
        $optimal = null;
        foreach ($actions as $action) {
            if ($action['is_optimal']) {
                $optimal = $action;
                break;
            }
        }

        if ($optimal !== null) {
            self::assertSame('ok', $optimal['plan']['status']);
        } else {
            foreach ($actions as $action) {
                self::assertSame('non_converging', $action['plan']['status']);
            }
        }

        foreach ($actions as $action) {
            if ($action['plan']['status'] === 'non_converging') {
                self::assertFalse($action['is_optimal']);
                if ($optimal !== null) {
                    self::assertGreaterThan(1, $action['rank']);
                }
            }
        }
    }
}
