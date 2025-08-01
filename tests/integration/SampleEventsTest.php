<?php

declare(strict_types=1);

namespace Tests\integration;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Events\Model\BudgetLimit\Updated as BudgetLimitUpdated;
use FireflyIII\Events\Model\PiggyBank\ChangedAmount;
use FireflyIII\Events\UpdatedTransactionGroup;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\PiggyBank\PiggyBankRepository;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use Illuminate\Support\Facades\Event;

/**
 * @internal
 *
 * @coversNothing
 */
final class SampleEventsTest extends TestCase
{
    public function testTransactionUpdateDispatchesEvent(): void
    {
        $user            = $this->createAuthenticatedUser();
        $user->refresh();
        $this->actingAs($user);

        $currency        = TransactionCurrency::where('code', 'EUR')->firstOrFail();
        $assetType       = AccountType::where('type', AccountTypeEnum::ASSET->value)->firstOrFail();
        $revenueType     = AccountType::where('type', AccountTypeEnum::REVENUE->value)->firstOrFail();

        $asset           = Account::create([
            'name'            => 'Asset',
            'account_type_id' => $assetType->id,
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'virtual_balance' => '0',
            'active'          => true,
        ]);
        $revenue         = Account::create([
            'name'            => 'Revenue',
            'account_type_id' => $revenueType->id,
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'virtual_balance' => '0',
            'active'          => true,
        ]);

        $groupRepository = app(TransactionGroupRepositoryInterface::class);
        $groupRepository->setUser($user);
        $groupRepository->setUserGroup($user->userGroup);

        $group           = $groupRepository->store([
            'user'                    => $user,
            'user_group'              => $user->userGroup,
            'apply_rules'             => false,
            'fire_webhooks'           => false,
            'error_if_duplicate_hash' => false,
            'transactions'            => [
                [
                    'type'           => 'transfer',
                    'date'           => now(),
                    'currency_id'    => $currency->id,
                    'amount'         => '10',
                    'description'    => 'orig',
                    'source_id'      => $asset->id,
                    'destination_id' => $revenue->id,
                ],
            ],
        ]);

        Event::fake();

        $response        = $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $group->id]), [
            'group_title' => 'Updated title',
        ]);

        $response->assertStatus(200);
        Event::assertDispatched(UpdatedTransactionGroup::class);
    }

    public function testBudgetLimitUpdateDispatchesEvent(): void
    {
        $user          = $this->createAuthenticatedUser();
        $user->refresh();
        $currency      = TransactionCurrency::where('code', 'EUR')->firstOrFail();
        $budget        = Budget::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'name'          => 'Test budget',
            'active'        => true,
            'order'         => 1,
        ]);
        $limit         = BudgetLimit::create([
            'budget_id'               => $budget->id,
            'start_date'              => now()->startOfMonth(),
            'start_date_tz'           => config('app.timezone'),
            'end_date'                => now()->endOfMonth(),
            'end_date_tz'             => config('app.timezone'),
            'amount'                  => '100',
            'native_amount'           => '100',
            'transaction_currency_id' => $currency->id,
        ]);

        Event::fake();
        $limit->amount = '200';
        $limit->save();
        Event::assertDispatched(BudgetLimitUpdated::class);
    }

    public function testPiggyBankAmountChangeDispatchesEvent(): void
    {
        $user           = $this->createAuthenticatedUser();
        $user->refresh();
        $currency       = TransactionCurrency::where('code', 'EUR')->firstOrFail();
        $assetType      = AccountType::where('type', AccountTypeEnum::ASSET->value)->firstOrFail();
        $asset          = Account::create([
            'name'            => 'Asset2',
            'account_type_id' => $assetType->id,
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'virtual_balance' => '0',
            'active'          => true,
        ]);

        $piggyBank      = PiggyBank::create([
            'name'                    => 'Goal',
            'order'                   => 1,
            'target_amount'           => '100',
            'start_date'              => now(),
            'start_date_tz'           => config('app.timezone'),
            'target_date'             => now()->addMonth(),
            'target_date_tz'          => config('app.timezone'),
            'active'                  => true,
            'transaction_currency_id' => $currency->id,
            'native_target_amount'    => '100',
        ]);
        $piggyBank->accounts()->attach($asset->id, ['current_amount' => '0', 'native_current_amount' => '0']);

        $repository     = app(PiggyBankRepository::class);
        $repository->setUser($user);

        Event::fake();
        $repository->addAmount($piggyBank, $asset, '10');
        Event::assertDispatched(ChangedAmount::class);
    }
}
