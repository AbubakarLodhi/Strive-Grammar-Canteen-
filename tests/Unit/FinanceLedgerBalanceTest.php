<?php

namespace Tests\Unit;

use App\Services\Finance\FinanceLedger;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceLedgerBalanceTest extends TestCase
{
    public function test_rejects_lines_that_do_not_balance(): void
    {
        $this->expectException(ValidationException::class);

        (new FinanceLedger)->assertBalanced([
            ['ledger_account_id' => 'cash', 'debit' => 100, 'credit' => 0],
            ['ledger_account_id' => 'sales', 'debit' => 0, 'credit' => 50],
        ]);
    }

    public function test_rejects_a_line_with_both_debit_and_credit(): void
    {
        $this->expectException(ValidationException::class);

        (new FinanceLedger)->assertBalanced([
            ['ledger_account_id' => 'cash', 'debit' => 100, 'credit' => 100],
            ['ledger_account_id' => 'sales', 'debit' => 0, 'credit' => 0],
        ]);
    }

    public function test_accepts_balanced_debit_and_credit(): void
    {
        (new FinanceLedger)->assertBalanced([
            ['ledger_account_id' => 'cash', 'debit' => 1500, 'credit' => 0],
            ['ledger_account_id' => 'sales', 'debit' => 0, 'credit' => 1500],
        ]);

        $this->assertTrue(true);
    }

    public function test_default_bank_ledger_account_is_ubl(): void
    {
        $bank = collect(FinanceLedger::DEFAULT_ACCOUNTS)->firstWhere('code', FinanceLedger::BANK_ACCOUNT_CODE);

        $this->assertSame('UBL', $bank['name']);
        $this->assertTrue($bank['is_bank']);
    }

    public function test_next_bank_code_skips_ubl(): void
    {
        $ledger = new FinanceLedger;

        $this->assertSame('1010', $ledger->suggestNextBankCode([]));
        $this->assertSame('1020', $ledger->suggestNextBankCode(['1010', '1000']));
        $this->assertSame('1030', $ledger->suggestNextBankCode(['1010', '1020']));
    }

    public function test_next_custom_ledger_code_starts_at_6000(): void
    {
        $ledger = new FinanceLedger;

        $this->assertSame('6000', $ledger->suggestNextLedgerAccountCode(['1000', '1010']));
        $this->assertSame('6001', $ledger->suggestNextLedgerAccountCode(['6000']));
    }

    public function test_opening_a_bank_account_requires_an_account_number(): void
    {
        $this->expectException(ValidationException::class);

        (new FinanceLedger)->createBankAccount('merchant-id', 'HBL', 0, null, '');
    }
}
