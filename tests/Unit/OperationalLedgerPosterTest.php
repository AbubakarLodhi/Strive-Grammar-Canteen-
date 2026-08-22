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

    public function test_cash_purchase_debits_purchases_and_credits_cash(): void
    {
        $lines = $this->poster()->purchaseLinePlan(800, 800, 0);

        $this->assertSame([
            ['code' => '5000', 'debit' => 800.0, 'credit' => 0, 'description' => 'Purchases'],
            ['code' => '1000', 'debit' => 0, 'credit' => 800.0, 'description' => 'Amount paid'],
        ], $lines);

        $this->assertPlanBalances($lines);
    }

    public function test_expense_debits_operating_expenses_and_credits_cash(): void
    {
        $lines = $this->poster()->expenseLinePlan(250.5);

        $this->assertSame([
            ['code' => '5100', 'debit' => 250.5, 'credit' => 0, 'description' => 'Operating expense'],
            ['code' => '1000', 'debit' => 0, 'credit' => 250.5, 'description' => 'Expense paid'],
        ], $lines);

        $this->assertPlanBalances($lines);
    }

    public function test_paid_payroll_debits_payroll_and_credits_cash(): void
    {
        $lines = $this->poster()->payrollLinePlan(45000);

        $this->assertSame('5200', $lines[0]['code']);
        $this->assertSame(45000.0, $lines[0]['debit']);
        $this->assertSame('1000', $lines[1]['code']);
        $this->assertPlanBalances($lines);
    }

    public function test_sale_return_reverses_sales(): void
    {
        $lines = $this->poster()->saleReturnLinePlan(100);

        $this->assertSame('4000', $lines[0]['code']);
        $this->assertSame(100.0, $lines[0]['debit']);
        $this->assertSame('1000', $lines[1]['code']);
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
