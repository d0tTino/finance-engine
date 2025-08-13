<?php

declare(strict_types=1);

namespace Tests\unit\Modules\AI;

use FireflyIII\Api\V1\Requests\Simulations\DebtRequest;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
use FireflyIII\Console\Commands\Correction\CreatesGroupMemberships;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Tests\integration\TestCase;

/**
 * @group unit-test
 * @group ai
 */
final class DebtSimulationServiceBudgetTest extends TestCase
{
    public function testClampsNegativeCashFlow(): void
    {
        Cache::flush();

        $service  = new DebtSimulationService();
        $userId   = 'user';
        $groupId  = 'group';
        $accounts = [
            ['account_id' => 1, 'balance' => 100.0, 'apr' => 0.0, 'min_payment' => 60.0],
            ['account_id' => 2, 'balance' => 100.0, 'apr' => 0.0, 'min_payment' => 60.0],
        ];

        $plans = $service->simulate($userId, $groupId, $accounts, 50.0, 1);

        foreach ($plans[0]['schedule'] as $month) {
            self::assertGreaterThanOrEqual(0.0, $month['cash_flow']);
        }
    }

    public function testReturnsEmptyWhenNoStrategies(): void
    {
        Cache::flush();

        $service  = new DebtSimulationService([]);
        $userId   = 'user';
        $groupId  = 'group';
        $accounts = [
            ['account_id' => 1, 'balance' => 100.0, 'apr' => 5.0],
        ];

        $plans = $service->simulate($userId, $groupId, $accounts, 50.0, 1);

        self::assertSame([], $plans);
    }

    public function testValidationFailsWhenBudgetBelowMinimums(): void
    {
        Cache::flush();

        $user = $this->createAuthenticatedUser();
        CreatesGroupMemberships::createGroupMembership($user);
        $user->refresh();
        $this->be($user);

        $type     = AccountType::where('type', AccountTypeEnum::DEBT->value)->first();
        $account1 = Account::create([
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Debt 1',
            'active'          => true,
        ]);
        $account2 = Account::create([
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'account_type_id' => $type->id,
            'name'            => 'Debt 2',
            'active'          => true,
        ]);

        $payload = [
            'user_id'        => (string) $user->uuid,
            'group_id'       => null,
            'accounts'       => [
                [
                    'account_id'      => (string) $account1->uuid,
                    'balance'         => 100.0,
                    'apr'             => 5.0,
                    'minimum_payment' => 60.0,
                ],
                [
                    'account_id'      => (string) $account2->uuid,
                    'balance'         => 100.0,
                    'apr'             => 3.0,
                    'minimum_payment' => 70.0,
                ],
            ],
            'monthly_budget' => 100.0,
            'max_options'    => 1,
        ];

        request()->replace($payload);

        $rules     = (new DebtRequest())->rules();
        $validator = Validator::make($payload, $rules);
        (new DebtRequest())->withValidator($validator);

        self::assertTrue($validator->fails());
        self::assertArrayHasKey('monthly_budget', $validator->errors()->toArray());
    }
}
