<?php

namespace Tests\Unit;

use App\Enums\LedgerAccountType;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Services\Finance\FinanceLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExpenseAccountFromNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_expense_account_from_a_typed_name(): void
    {
        $merchant = $this->createMerchant();

        $account = (new FinanceLedger)->ensureExpenseAccountByName($merchant->id, 'Utilities');

        $this->assertSame($merchant->id, $account->merchant_id);
        $this->assertSame('Utilities', $account->name);
        $this->assertSame(LedgerAccountType::Expense, $account->type);
        $this->assertFalse($account->is_system);
        $this->assertSame('5300', $account->code);
        $this->assertTrue($account->is_active);
    }

    public function test_it_reuses_an_existing_expense_account_by_name(): void
    {
        $merchant = $this->createMerchant();
        $ledger = new FinanceLedger;

        $first = $ledger->ensureExpenseAccountByName($merchant->id, 'Rent');
        $second = $ledger->ensureExpenseAccountByName($merchant->id, 'rent');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LedgerAccount::query()
            ->where('merchant_id', $merchant->id)
            ->where('type', LedgerAccountType::Expense)
            ->where('is_system', false)
            ->count());
    }

    public function test_it_rejects_a_blank_expense_account_name(): void
    {
        $merchant = $this->createMerchant();

        $this->expectException(ValidationException::class);

        (new FinanceLedger)->ensureExpenseAccountByName($merchant->id, '   ');
    }

    private function createMerchant(): Merchant
    {
        return Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => Str::uuid().'@example.com',
            'name' => 'Test Merchant',
            'address_line_1' => '1 Test Street',
            'city' => 'Lahore',
            'status' => Merchant::STATUS_VERIFIED,
            'is_active' => true,
            'password' => 'password',
        ]);
    }
}
