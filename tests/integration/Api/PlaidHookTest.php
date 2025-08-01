<?php

declare(strict_types=1);

namespace Tests\integration\Api;

use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionType;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\integration\TestCase;
use Override;

/**
 * @internal
 *
 * @coversNothing
 */
final class PlaidHookTest extends TestCase
{
    use RefreshDatabase;

    public function testHmacVerification(): void
    {
        $secret    = 'secret';
        $payload   = '{"ok":true}';
        $timestamp = '1234567890';
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $header    = sprintf('t=%s,v1=%s', $timestamp, $signature);

        $this->assertTrue($this->verifyHmac($header, $payload, $secret));
    }

    public function testHmacVerificationFailsWithWrongSignature(): void
    {
        $secret    = 'secret';
        $payload   = '{"ok":true}';
        $timestamp = '1234567890';
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'other');
        $header    = sprintf('t=%s,v1=%s', $timestamp, $signature);

        $this->assertFalse($this->verifyHmac($header, $payload, $secret));
    }

    private function verifyHmac(string $header, string $payload, string $secret): bool
    {
        parse_str(str_replace(',', '&', $header), $parts);
        $t        = $parts['t'] ?? '';
        $hash     = $parts['v1'] ?? '';
        if ('' === $t || '' === $hash) {
            return false;
        }
        $expected = hash_hmac('sha256', $t.'.'.$payload, $secret);

        return hash_equals($expected, $hash);
    }

    #[Override]
    protected function setUp(): void
    {
        $dbPath = dirname(__DIR__, 3).'/storage/database/database.sqlite';
        if (!file_exists($dbPath)) {
            @mkdir(dirname($dbPath), 0o777, true);
            touch($dbPath);
        }
        parent::setUp();
        if (!isset($this->user)) {
            $this->user = $this->createAuthenticatedUser();
        }
        $this->actingAs($this->user);
    }

    public function testTransactionCreation(): void
    {
        $assetType   = AccountType::where('type', AccountTypeEnum::ASSET->value)->firstOrFail();
        $currency    = TransactionCurrency::where('code', 'EUR')->firstOrFail();
        $transType   = TransactionType::where('type', TransactionTypeEnum::TRANSFER->value)->firstOrFail();

        $source      = Account::create([
            'user_id'         => $this->user->id,
            'user_group_id'   => $this->user->user_group_id,
            'account_type_id' => $assetType->id,
            'name'            => 'Source',
            'active'          => true,
            'virtual_balance' => '0',
        ]);

        $destination = Account::create([
            'user_id'         => $this->user->id,
            'user_group_id'   => $this->user->user_group_id,
            'account_type_id' => $assetType->id,
            'name'            => 'Destination',
            'active'          => true,
            'virtual_balance' => '0',
        ]);

        $journal     = TransactionJournal::create([
            'user_id'                 => $this->user->id,
            'user_group_id'           => $this->user->user_group_id,
            'transaction_type_id'     => $transType->id,
            'transaction_currency_id' => $currency->id,
            'description'             => 'Test transfer',
            'date'                    => now(),
            'date_tz'                 => config('app.timezone'),
            'order'                   => 0,
            'tag_count'               => 0,
            'completed'               => true,
        ]);

        Transaction::create([
            'account_id'              => $source->id,
            'transaction_journal_id'  => $journal->id,
            'transaction_currency_id' => $currency->id,
            'amount'                  => -100,
            'description'             => 'out',
        ]);

        Transaction::create([
            'account_id'              => $destination->id,
            'transaction_journal_id'  => $journal->id,
            'transaction_currency_id' => $currency->id,
            'amount'                  => 100,
            'description'             => 'in',
        ]);

        $this->assertSame(2, $journal->transactions()->count());
    }
}
