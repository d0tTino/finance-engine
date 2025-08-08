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
use Mockery;
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
        $account2  = Account::create([
            'user_id'         => $user1->id,
            'user_group_id'   => $user1->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Debt 2',
            'active'          => true,
        ]);
        $this->be($user1);

        $accounts1 = [
            [
                'account_id'       => $account1->id,
                'balance'          => 100.0,
                'apr'              => 5.0,
                'min_payment'      => 0.0,
                'minimum_payment'  => 0.0,
            ],
            [
                'account_id'       => $account2->id,
                'balance'          => 200.0,
                'apr'              => 3.0,
                'min_payment'      => 0.0,
                'minimum_payment'  => 0.0,
            ],
        ];

        $payload1 = [
            'user_id'        => '1f111111-1111-1111-1111-111111111111',
            'group_id'       => null,
            'accounts'       => $accounts1,
            'monthly_budget' => $budget,
            'max_options'    => $maxOptions,
        ];

        $response1  = $this->postJson('/api/v1/simulations/debt', $payload1)->assertOk();
        $response1b = $this->postJson('/api/v1/simulations/debt', $payload1)->assertOk();
        self::assertSame($response1->json('data.proposed_actions'), $response1b->json('data.proposed_actions'));

        $response1->assertJsonStructure([
            'data' => [
                'proposed_actions' => [
                    [
                        'rank',
                        'is_optimal',
                        'plan' => [
                            'strategy',
                            'schedule',
                            'monthly_cash_flow',
                        ],
                        'metrics' => [
                            'interest_saved',
                            'time_to_payoff_months',
                        ],
                        'cost_of_deviation' => [
                            'amount' => ['value', 'currency'],
                            'time'   => ['value', 'unit'],
                        ],
                        'meta' => ['ranking_heuristic'],
                    ],
                ],
            ],
            'meta' => ['analysis_id', 'ranking_heuristic'],
        ]);
        self::assertTrue(Str::isUuid($response1->json('meta.analysis_id')));
        self::assertNotEmpty($response1->json('data.proposed_actions.0.plan.schedule'));
        self::assertArrayHasKey('cost_of_deviation', $response1->json('data.proposed_actions.0'));
        self::assertArrayHasKey(
            (string) $account1->id,
            $response1->json('data.proposed_actions.0.plan.schedule.0.payments')
        );

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
        $account4 = Account::create([
            'user_id'         => $user2->id,
            'user_group_id'   => $user2->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Debt 4',
            'active'          => true,
        ]);
        $this->be($user2);

        $accounts2 = [
            [
                'account_id'       => $account3->id,
                'balance'          => 100.0,
                'apr'              => 5.0,
                'min_payment'      => 0.0,
                'minimum_payment'  => 0.0,
            ],
            [
                'account_id'       => $account4->id,
                'balance'          => 200.0,
                'apr'              => 3.0,
                'min_payment'      => 0.0,
                'minimum_payment'  => 0.0,
            ],
        ];

        $payload2 = [
            'user_id'        => '2f222222-2222-2222-2222-222222222222',
            'group_id'       => null,
            'accounts'       => $accounts2,
            'monthly_budget' => $budget,
            'max_options'    => $maxOptions,
        ];

        $response2  = $this->postJson('/api/v1/simulations/debt', $payload2)->assertOk();
        $response2b = $this->postJson('/api/v1/simulations/debt', $payload2)->assertOk();
        self::assertSame($response2->json('data.proposed_actions'), $response2b->json('data.proposed_actions'));
    }

    public function testUuidValidationAndNullableGroupId(): void
    {
        $accounts = [
            [
                'account_id'       => 1,
                'balance'          => 100.0,
                'apr'              => 5.0,
                'min_payment'      => 0.0,
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

        $payload = [
            'user_id'        => '1f111111-1111-1111-1111-111111111111',
            'group_id'       => null,
            'accounts'       => [[
                'account_id'       => $foreign->id,
                'balance'          => 100.0,
                'apr'              => 5.0,
                'min_payment'      => 0.0,
                'minimum_payment'  => 0.0,
            ]],
            'monthly_budget' => 50.0,
            'max_options'    => 2,
        ];

        $this->postJson('/api/v1/simulations/debt', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accounts.0.account_id']);
    }
}
