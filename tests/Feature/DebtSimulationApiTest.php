<?php

declare(strict_types=1);

namespace Tests\Feature;

use FireflyIII\Console\Commands\Correction\CreatesGroupMemberships;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\User;
use Illuminate\Support\Facades\Cache;
use FireflyIII\Http\Middleware\Authenticate;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use FireflyIII\Http\Middleware\OpaMiddleware;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Tests\integration\TestCase as IntegrationTestCase;
use function Safe\putenv;

final class DebtSimulationApiTest extends IntegrationTestCase
{
    public function testUserIsolationAndCaching(): void
    {
        Cache::setDefaultDriver('file');
        putenv('CACHE_DRIVER=file');
        Cache::flush();
        $this->withoutMiddleware([Authenticate::class, 'auth:api', 'auth:api,sanctum', EnsureFrontendRequestsAreStateful::class, OpaMiddleware::class]);
        config(['auth.defaults.guard' => 'web']);

        $budget     = 50.0;
        $maxOptions = 2;

        $user1 = User::create(['email' => 'user1@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user1);
        $user1->refresh();

        $type      = AccountType::where('type', AccountTypeEnum::DEBT->value)->first();
        $account1  = Account::create([
            'user_id'         => $user1->id,
            'user_group_id'   => $user1->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Debt 1',
            'active'          => true,
        ]);
        $account1->refresh();
        $account2  = Account::create([
            'user_id'         => $user1->id,
            'user_group_id'   => $user1->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Debt 2',
            'active'          => true,
        ]);
        $account2->refresh();
        $this->be($user1);

        $accounts1 = [
            [
                'account_id'      => (string) $account1->uuid,
                'balance'         => 100.0,
                'apr'             => 5.0,
                'minimum_payment' => 0.0,
            ],
            [
                'account_id'      => (string) $account2->uuid,
                'balance'         => 200.0,
                'apr'             => 3.0,
                'minimum_payment' => 0.0,
            ],
        ];

        $payload1 = [
            'user_id'        => (string) $user1->uuid,
            'group_id'       => null,
            'accounts'       => $accounts1,
            'monthly_budget' => $budget,
            'max_options'    => $maxOptions,
        ];

        $response1  = $this->postJson('/api/v1/simulations/debt', $payload1)->assertOk();
        $response1b = $this->postJson('/api/v1/simulations/debt', $payload1)->assertOk();
        self::assertSame($response1->json('proposed_actions'), $response1b->json('proposed_actions'));

        $response1->assertJsonStructure([
            'analysis_id',
            'proposed_actions' => [
                [
                    'rank',
                    'is_optimal',
                    'plan'    => ['strategy', 'schedule'],
                    'metrics' => [
                        'interest_saved',
                        'time_to_payoff_months',
                        'total_interest_paid',
                        'monthly_cash_flow',
                    ],
                    'meta'             => ['ranking_heuristic', 'tradeoffs'],
                    'cost_of_deviation' => ['currency', 'time' => ['months']],
                ],
            ],
        ]);
        self::assertTrue(Str::isUuid($response1->json('analysis_id')));
        self::assertNotEmpty($response1->json('proposed_actions.0.plan.schedule'));
        self::assertArrayHasKey('cost_of_deviation', $response1->json('proposed_actions.0'));
        self::assertArrayHasKey('months', $response1->json('proposed_actions.0.cost_of_deviation.time'));
        self::assertArrayHasKey('ranking_heuristic', $response1->json('proposed_actions.0.meta'));
        self::assertArrayHasKey('tradeoffs', $response1->json('proposed_actions.0.meta'));
        self::assertArrayHasKey(
            (string) $account1->uuid,
            $response1->json('proposed_actions.0.plan.schedule.0.payments')
        );

        $this->postJson('/api/v1/simulations/debt', array_merge($payload1, ['user_id' => (string) Str::uuid()]))
            ->assertStatus(422);

        $user2 = User::create(['email' => 'user2@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user2);
        $user2->refresh();

        $account3 = Account::create([
            'user_id'         => $user2->id,
            'user_group_id'   => $user2->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Debt 3',
            'active'          => true,
        ]);
        $account3->refresh();
        $account4 = Account::create([
            'user_id'         => $user2->id,
            'user_group_id'   => $user2->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Debt 4',
            'active'          => true,
        ]);
        $account4->refresh();
        $this->be($user2);

        $accounts2 = [
            [
                'account_id'      => (string) $account3->uuid,
                'balance'         => 100.0,
                'apr'             => 5.0,
                'minimum_payment' => 0.0,
            ],
            [
                'account_id'      => (string) $account4->uuid,
                'balance'         => 200.0,
                'apr'             => 3.0,
                'minimum_payment' => 0.0,
            ],
        ];

        $payload2 = [
            'user_id'        => (string) $user2->uuid,
            'group_id'       => null,
            'accounts'       => $accounts2,
            'monthly_budget' => $budget,
            'max_options'    => $maxOptions,
        ];

        $response2  = $this->postJson('/api/v1/simulations/debt', $payload2)->assertOk();
        $response2b = $this->postJson('/api/v1/simulations/debt', $payload2)->assertOk();
        self::assertSame($response2->json('proposed_actions'), $response2b->json('proposed_actions'));
    }

    public function testUuidValidationAndNullableGroupId(): void
    {
        $accounts = [
            [
                'account_id'       => (string) Str::uuid(),
                'balance'          => 100.0,
                'apr'              => 5.0,
                'minimum_payment'  => 0.0,
            ],
        ];

        $invalid = [
            'user_id'        => 'not-a-uuid',
            'group_id'       => 'also-not-a-uuid',
            'accounts'       => $accounts,
            'monthly_budget' => 50.0,
            'max_options'    => 2,
        ];
        $rules = (new \FireflyIII\Api\V1\Requests\Simulations\DebtRequest())->rules();
        $validator = Validator::make($invalid, $rules);
        self::assertTrue($validator->fails());
        self::assertArrayHasKey('user_id', $validator->errors()->toArray());
        self::assertArrayHasKey('group_id', $validator->errors()->toArray());

        $valid = $invalid;
        $valid['user_id']  = (string) Str::uuid();
        $valid['group_id'] = null;
        $validator = Validator::make($valid, $rules);
        self::assertFalse($validator->fails());
    }

    public function testUnauthorizedAccountAccess(): void
    {
        Cache::setDefaultDriver('file');
        putenv('CACHE_DRIVER=file');
        Cache::flush();
        $this->withoutMiddleware([Authenticate::class, 'auth:api', 'auth:api,sanctum', EnsureFrontendRequestsAreStateful::class, OpaMiddleware::class]);
        config(['auth.defaults.guard' => 'web']);

        $user1 = User::create(['email' => 'user1@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user1);
        $user1->refresh();
        $this->be($user1);

        $user2 = User::create(['email' => 'user2@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user2);
        $user2->refresh();

        $type     = AccountType::where('type', AccountTypeEnum::DEBT->value)->first();
        $foreign  = Account::create([
            'user_id'         => $user2->id,
            'user_group_id'   => $user2->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Foreign debt',
            'active'          => true,
        ]);
        $foreign->refresh();

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

    public function testRejectsGroupIdFromDifferentUserGroup(): void
    {
        // Configure environment and disable auth middleware
        Cache::setDefaultDriver('file');
        putenv('CACHE_DRIVER=file');
        Cache::flush();
        $this->withoutMiddleware([Authenticate::class, 'auth:api', 'auth:api,sanctum', EnsureFrontendRequestsAreStateful::class, OpaMiddleware::class]);
        config(['auth.defaults.guard' => 'web']);

        $user1 = User::create(['email' => 'user1@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user1);
        $user1->refresh();
        $this->be($user1);

        $user2 = User::create(['email' => 'user2@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user2);
        $user2->refresh();

        $type     = AccountType::where('type', AccountTypeEnum::DEBT->value)->first();
        $account1 = Account::create([
            'user_id'         => $user1->id,
            'user_group_id'   => $user1->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Debt 1',
            'active'          => true,
        ]);
        $account1->refresh();

        $payload = [
            'user_id'        => (string) $user1->uuid,
            'group_id'       => (string) $user2->user_group_id,
            'accounts'       => [[
                'account_id'       => (string) $account1->uuid,
                'balance'          => 100.0,
                'apr'              => 5.0,
                'minimum_payment'  => 0.0,
            ]],
            'monthly_budget' => 50.0,
            'max_options'    => 2,
        ];

        $this->postJson('/api/v1/simulations/debt', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['group_id']);
    }
}
