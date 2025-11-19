<?php

declare(strict_types=1);

namespace Tests\integration\AI;

use FireflyIII\Console\Commands\Correction\CreatesGroupMemberships;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Http\Middleware\OpaMiddleware;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\User;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;
use Tests\integration\TestCase;
use Override;

/**
 * @group integration-test
 * @group ai
 *
 * @internal
 */
final class DebtSimulationEndpointTest extends TestCase
{
    private User $user;

    private AccountType $debtType;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureUuidColumns();

        Cache::setDefaultDriver('array');
        Cache::flush();

        config(['auth.guards.api' => ['driver' => 'token', 'provider' => 'users']]);

        $this->withoutMiddleware([
            EnsureFrontendRequestsAreStateful::class,
            OpaMiddleware::class,
        ]);

        $this->user = User::create([
            'email'    => 'test@email.com',
            'password' => 'secret',
        ]);
        CreatesGroupMemberships::createGroupMembership($this->user);
        $this->user->refresh();

        if (null === $this->user->uuid) {
            $this->user->uuid = (string) Str::uuid();
            $this->user->save();
            $this->user->refresh();
        }

        Sanctum::actingAs($this->user);

        $this->debtType = AccountType::where('type', AccountTypeEnum::DEBT->value)->firstOrFail();
    }

    public function testReturnsRankedPlansWithCashFlowCorrections(): void
    {
        $accountA = $this->createDebtAccount('Debt One');
        $accountB = $this->createDebtAccount('Debt Two');

        self::assertNotNull($this->user->userGroup);
        self::assertContains($accountA->id, $this->user->accounts()->pluck('id')->all());
        self::assertContains($accountB->id, $this->user->accounts()->pluck('id')->all());
        self::assertNotNull(Account::query()->where('uuid', (string) $accountA->uuid)->first());
        self::assertNotNull(Account::query()->where('uuid', (string) $accountB->uuid)->first());

        /** @var AccountRepositoryInterface $repository */
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($this->user);
        self::assertCount(2, $repository->getAccountsById([$accountA->id, $accountB->id]));

        $uuidMap    = Account::query()->whereIn('uuid', [(string) $accountA->uuid, (string) $accountB->uuid])->pluck('id', 'uuid');
        $authorized = array_flip($repository->getAccountsById($uuidMap->values()->all())->pluck('id')->all());
        self::assertArrayHasKey((int) $uuidMap[(string) $accountA->uuid], $authorized);
        self::assertArrayHasKey((int) $uuidMap[(string) $accountB->uuid], $authorized);

        $payload = [
            'user_id'        => (string) $this->user->uuid,
            'group_id'       => null,
            'accounts'       => [
                [
                    'account_id'      => (string) $accountA->uuid,
                    'balance'         => 100.0,
                    'apr'             => 0.05,
                    'minimum_payment' => 10.0,
                ],
                [
                    'account_id'      => (string) $accountB->uuid,
                    'balance'         => 200.0,
                    'apr'             => 0.03,
                    'minimum_payment' => 10.0,
                ],
            ],
            'monthly_budget' => 75.0,
            'max_options'    => 4,
        ];

        $response = $this->postJson('/api/v1/simulations/debt', $payload)->assertOk();

        $response->assertJsonStructure([
            'analysis_id',
            'proposed_actions' => [[
                'rank',
                'is_optimal',
                'plan'    => ['strategy', 'schedule', 'status'],
                'metrics' => [
                    'interest_saved',
                    'time_to_payoff_months',
                    'total_interest_paid',
                    'monthly_cash_flow',
                ],
                'meta' => ['ranking_heuristic', 'tradeoffs', 'ranking_reason', 'strategy_explanation'],
            ]],
        ]);

        $analysisId = $response->json('analysis_id');
        self::assertIsString($analysisId);
        self::assertTrue(Str::isUuid($analysisId));

        $actions = $response->json('proposed_actions');
        self::assertIsArray($actions);
        self::assertNotEmpty($actions);

        $ranks = array_map(static fn (array $action): int => (int) $action['rank'], $actions);
        self::assertSame(range(1, count($ranks)), $ranks);

        $nonOptimal = array_filter($actions, static fn (array $action): bool => false === (bool) $action['is_optimal']);
        self::assertNotEmpty($nonOptimal);
        foreach ($nonOptimal as $plan) {
            self::assertArrayHasKey('cost_of_deviation', $plan);
            self::assertArrayHasKey('currency', $plan['cost_of_deviation']);
            self::assertArrayHasKey('time_months', $plan['cost_of_deviation']);
        }

        $primaryPlan = $actions[0];
        self::assertSame(1, $primaryPlan['rank']);

        $optimalPlans = array_values(array_filter($actions, static fn (array $action): bool => (bool) $action['is_optimal']));
        self::assertNotEmpty($optimalPlans);

        foreach ($optimalPlans as $optimalPlan) {
            self::assertArrayHasKey('cost_of_deviation', $optimalPlan);
            self::assertArrayHasKey('currency', $optimalPlan['cost_of_deviation']);
            self::assertArrayHasKey('time_months', $optimalPlan['cost_of_deviation']);
            self::assertEqualsWithDelta(0.0, (float) $optimalPlan['cost_of_deviation']['currency'], 0.0001);
            self::assertEquals(0, (int) $optimalPlan['cost_of_deviation']['time_months']);
        }

        $bestPlan  = $optimalPlans[0];
        $schedule  = $bestPlan['plan']['schedule'];
        $cashFlows = $bestPlan['metrics']['monthly_cash_flow'];
        self::assertIsArray($schedule);
        self::assertIsArray($cashFlows);
        self::assertNotEmpty($schedule);
        self::assertSame(count($schedule), count($cashFlows));

        foreach ($schedule as $index => $monthData) {
            self::assertSame($monthData['month'], $cashFlows[$index]['month']);
            self::assertEqualsWithDelta($monthData['cash_flow'], $cashFlows[$index]['cash_flow'], 0.01);
        }
    }

    public function testRejectsAccountsOutsideOfUserContext(): void
    {
        $foreignUser = User::create([
            'email'    => 'foreign@example.com',
            'password' => 'secret',
        ]);
        CreatesGroupMemberships::createGroupMembership($foreignUser);
        $foreignUser->refresh();
        if (null === $foreignUser->uuid) {
            $foreignUser->uuid = (string) Str::uuid();
            $foreignUser->save();
            $foreignUser->refresh();
        }

        $foreignGroup = $foreignUser->userGroup;
        if (null !== $foreignGroup && null === $foreignGroup->uuid) {
            $foreignGroup->uuid = (string) Str::uuid();
            $foreignGroup->save();
            $foreignGroup->refresh();
        }

        $foreignAccount = Account::create([
            'user_id'         => $foreignUser->id,
            'user_group_id'   => $foreignUser->user_group_id,
            'account_type_id' => $this->debtType->id,
            'name'            => 'Foreign Debt',
            'active'          => true,
        ]);
        $foreignAccount->refresh();
        if (null === $foreignAccount->uuid) {
            $foreignAccount->uuid = (string) Str::uuid();
            $foreignAccount->save();
            $foreignAccount->refresh();
        }

        self::assertNotContains($foreignAccount->id, $this->user->accounts()->pluck('id')->all());

        $payload = [
            'user_id'        => (string) $this->user->uuid,
            'group_id'       => null,
            'accounts'       => [[
                'account_id'      => (string) $foreignAccount->uuid,
                'balance'         => 150.0,
                'apr'             => 7.0,
                'minimum_payment' => 10.0,
            ]],
            'monthly_budget' => 200.0,
            'max_options'    => 2,
        ];

        $this->postJson('/api/v1/simulations/debt', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accounts.0.account_id']);
    }

    private function createDebtAccount(string $name): Account
    {
        $account = Account::create([
            'user_id'         => $this->user->id,
            'user_group_id'   => $this->user->user_group_id,
            'account_type_id' => $this->debtType->id,
            'name'            => $name,
            'active'          => true,
        ]);
        $account->refresh();
        if (null === $account->uuid) {
            $account->uuid = (string) Str::uuid();
            $account->save();
            $account->refresh();
        }

        return $account;
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

        if (!Schema::hasColumn('user_groups', 'uuid')) {
            Schema::table('user_groups', static function (Blueprint $table): void {
                $table->uuid('uuid')->nullable();
            });
        }
    }
}
