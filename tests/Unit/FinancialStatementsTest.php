<?php

namespace Tests\Unit;

use App\Enums\LedgerAccountType;
use App\Services\Finance\FinancialStatements;
use Tests\TestCase;

class FinancialStatementsTest extends TestCase
{
    public function test_trial_balance_puts_asset_closing_on_debit(): void
    {
        $statements = new FinancialStatements;
        $closing = $statements->closingBalance(LedgerAccountType::Asset, 1000, 500, 200);

        $this->assertSame(1300.0, $closing);
        $this->assertSame(
            ['debit' => 1300.0, 'credit' => 0.0],
            $statements->trialBalanceSides(LedgerAccountType::Asset, $closing)
        );
    }

    public function test_monthly_profit_is_income_minus_expense(): void
    {
        $statements = new FinancialStatements;
        $sales = $statements->periodAmount(LedgerAccountType::Income, 0, 1500);
        $expense = $statements->periodAmount(LedgerAccountType::Expense, 400, 0);

        $this->assertSame(1500.0, $sales);
        $this->assertSame(400.0, $expense);
        $this->assertSame(1100.0, round($sales - $expense, 2));
    }

    public function test_balance_sheet_identity_includes_year_profit(): void
    {
        $statements = new FinancialStatements;
        $cash = $statements->closingBalance(LedgerAccountType::Asset, 0, 1500, 400);
        $equity = $statements->closingBalance(LedgerAccountType::Equity, 0, 0, 0);
        $profit = 1100.0;

        $this->assertSame(1100.0, $cash);
        $this->assertSame($cash, round($equity + $profit, 2));
    }

    public function test_year_window_covers_the_full_calendar_year(): void
    {
        $window = (new FinancialStatements)->periodWindow(2026);

        $this->assertSame('year', $window['scope']);
        $this->assertSame('2026', $window['period_label']);
        $this->assertSame('Year', $window['amount_label']);
        $this->assertSame('2026-01-01', $window['start']->toDateString());
        $this->assertSame('2026-12-31', $window['end']->toDateString());
    }

    public function test_month_window_covers_only_that_month(): void
    {
        $window = (new FinancialStatements)->periodWindow(2026, 8);

        $this->assertSame('month', $window['scope']);
        $this->assertSame('August 2026', $window['period_label']);
        $this->assertSame('This month', $window['amount_label']);
        $this->assertSame('2026-08-01', $window['start']->toDateString());
        $this->assertSame('2026-08-31', $window['end']->toDateString());
    }
}
