<?php

namespace Tests\Unit;

use App\Services\Finance\FinanceLedger;
use App\Services\Finance\OperationalLedgerPoster;
use Tests\TestCase;

class OperationalLedgerPosterTest extends TestCase
{
    public function test_cash_sale_debits_cash_and_credits_sales(): void
    {
        $lines = $this->poster()->saleLinePlan(1500, 1500, 0);

        $this->assertSame([
            ['code' => '1000', 'debit' => 1500.0, 'credit' => 0, 'description' => 'Amount received'],
            ['code' => '4000', 'debit' => 0, 'credit' => 1500.0, 'description' => 'Sales'],
        ], $lines);

        $this->assertPlanBalances($lines);
    }

    public function test_credit_sale_with_partial_payment_splits_cash_and_receivable(): void
    {
        $lines = $this->poster()->saleLinePlan(1000, 400, 600);

        $this->assertSame([
            ['code' => '1000', 'debit' => 400.0, 'credit' => 0, 'description' => 'Amount received'],
            ['code' => '1100', 'debit' => 600.0, 'credit' => 0, 'description' => 'Amount receivable'],
            ['code' => '4000', 'debit' => 0, 'credit' => 1000.0, 'description' => 'Sales'],
        ], $lines);

        $this->assertPlanBalances($lines);
    }

    public function test_bank_sale_uses_bank_account(): void
    {
        $lines = $this->poster()->saleLinePlan(200, 200, 0, true);

        $this->assertSame('1010', $lines[0]['code']);
        $this->assertPlanBalances($lines);
    }

    public function test_cash_purchase_debits_inventory_and_credits_vendor_payable_not_cash(): void
    {
        $lines = $this->poster()->purchaseLinePlan(800, 800, 0);

        $this->assertSame([
            ['code' => '1400', 'debit' => 800.0, 'credit' => 0, 'description' => 'Purchase inventory'],
            ['code' => '2000', 'debit' => 0, 'credit' => 800.0, 'description' => 'Purchase amount payable'],
        ], $lines);

        $this->assertPlanBalances($lines);
        $this->assertSame([], collect($lines)->where('code', '1000')->all());
    }

    public function test_purchase_line_descriptions_include_the_vendor_name(): void
    {
        $lines = $this->poster()->purchaseLinePlan(1000, 400, 600, false, 'Paper House', '2001', 'PUR-1001');

        $this->assertSame([
            ['code' => '1400', 'debit' => 1000.0, 'credit' => 0, 'description' => 'Purchase PUR-1001 inventory — Paper House'],
            ['code' => '2001', 'debit' => 0, 'credit' => 1000.0, 'description' => 'Purchase PUR-1001 amount payable — Paper House'],
        ], $lines);

        $this->assertPlanBalances($lines);
    }

    public function test_bank_purchase_credits_bank_not_cash_in_hand(): void
    {
        $lines = $this->poster()->purchaseLinePlan(1000, 400, 600, true, 'Paper House', '2001', 'PUR-1002');

        $this->assertSame([
            ['code' => '1400', 'debit' => 1000.0, 'credit' => 0, 'description' => 'Purchase PUR-1002 inventory — Paper House'],
            ['code' => '1010', 'debit' => 0, 'credit' => 400.0, 'description' => 'Purchase PUR-1002 amount paid (bank) — Paper House'],
            ['code' => '2001', 'debit' => 0, 'credit' => 600.0, 'description' => 'Purchase PUR-1002 amount payable — Paper House'],
        ], $lines);

        $this->assertPlanBalances($lines);
        $this->assertSame([], collect($lines)->where('code', '1000')->all());
    }

    public function test_sale_with_cogs_debits_cogs_and_credits_inventory(): void
    {
        $lines = $this->poster()->saleLinePlan(1000, 1000, 0, false, 400);

        $cogs = collect($lines)->firstWhere('code', FinanceLedger::COGS_ACCOUNT_CODE);
        $inventory = collect($lines)->firstWhere('code', FinanceLedger::INVENTORY_ACCOUNT_CODE);

        $this->assertNotNull($cogs);
        $this->assertSame(400.0, $cogs['debit']);
        $this->assertNotNull($inventory);
        $this->assertSame(400.0, $inventory['credit']);
        $this->assertPlanBalances($lines);
    }

    public function test_expense_debits_operating_expenses_and_credits_bank_not_cash(): void
    {
        $lines = $this->poster()->expenseLinePlan(250.5);

        $this->assertSame([
            ['code' => '5100', 'debit' => 250.5, 'credit' => 0, 'description' => 'Operating expense'],
            ['code' => '1010', 'debit' => 0, 'credit' => 250.5, 'description' => 'Expense paid'],
        ], $lines);

        $this->assertPlanBalances($lines);
    }

    public function test_paid_payroll_debits_payroll_and_credits_bank_not_cash(): void
    {
        $lines = $this->poster()->payrollLinePlan(45000);

        $this->assertSame('5200', $lines[0]['code']);
        $this->assertSame(45000.0, $lines[0]['debit']);
        $this->assertSame('1010', $lines[1]['code']);
        $this->assertPlanBalances($lines);
    }

    public function test_sale_return_without_cogs_posts_no_settlement_lines(): void
    {
        $lines = $this->poster()->saleReturnLinePlan(100);

        $this->assertSame([], $lines);
    }

    public function test_sale_return_only_restores_inventory(): void
    {
        $lines = $this->poster()->saleReturnLinePlan(
            total: 400,
            refundToBank: false,
            creditCustomer: false,
            cogs: 150,
            cashRefund: 100,
            receivableCredit: 300,
        );

        $this->assertCount(2, $lines);
        $this->assertSame(150.0, collect($lines)->firstWhere('code', FinanceLedger::INVENTORY_ACCOUNT_CODE)['debit']);
        $this->assertSame(150.0, collect($lines)->firstWhere('code', FinanceLedger::COGS_ACCOUNT_CODE)['credit']);
        $this->assertNull(collect($lines)->firstWhere('code', '4000'));
        $this->assertNull(collect($lines)->firstWhere('code', '1000'));
        $this->assertNull(collect($lines)->firstWhere('code', '1100'));
        $this->assertPlanBalances($lines);
    }

    private function poster(): OperationalLedgerPoster
    {
        return new OperationalLedgerPoster(new FinanceLedger);
    }

    /**
     * @param  list<array{code: string, debit: float, credit: float, description: string}>  $lines
     */
    private function assertPlanBalances(array $lines): void
    {
        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        $this->assertSame($debit, $credit);
        $this->assertGreaterThan(0, $debit);
    }
}
