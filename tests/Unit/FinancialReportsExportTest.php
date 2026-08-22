<?php

namespace Tests\Unit;

use App\Filament\Exports\FinancialReportsExport;
use Tests\TestCase;

class FinancialReportsExportTest extends TestCase
{
    public function test_export_builds_trial_profit_and_balance_sheets(): void
    {
        $export = new FinancialReportsExport($this->statements());
        $sheets = $export->sheets();

        $this->assertCount(3, $sheets);
        $this->assertSame('Trial Balance', $sheets[0]->title());
        $this->assertSame('Profit and Loss', $sheets[1]->title());
        $this->assertSame('Balance Sheet', $sheets[2]->title());

        $trial = $sheets[0]->array();
        $this->assertSame('Cash in Hand', $trial[1][0]);
        $this->assertSame(500.0, $trial[1][2]);
        $this->assertSame(['Total', '', 500.0, 500.0], $trial[3]);

        $profit = $sheets[1]->array();
        $this->assertSame('Net profit', $profit[array_key_last($profit)][0]);
        $this->assertSame(200.0, $profit[array_key_last($profit)][1]);

        $balance = $sheets[2]->array();
        $this->assertSame('Total liabilities and equity', $balance[array_key_last($balance)][0]);
        $this->assertSame(500.0, $balance[array_key_last($balance)][1]);
    }

    public function test_pdf_view_renders_all_three_statements(): void
    {
        $html = view('exports.financial-reports-pdf', [
            'company' => 'Strive Uniform and Bookshop',
            'statements' => $this->statements(),
        ])->render();

        $this->assertStringContainsString('Trial Balance', $html);
        $this->assertStringContainsString('Profit and Loss Account', $html);
        $this->assertStringContainsString('Balance Sheet', $html);
        $this->assertStringContainsString('Cash in Hand', $html);
        $this->assertStringContainsString('Sales', $html);
        $this->assertStringNotContainsString('<th>Code</th>', $html);
        $this->assertStringNotContainsString('1000', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function statements(): array
    {
        return [
            'period_label' => 'August 2026',
            'as_at' => '31/08/2026',
            'scope' => 'month',
            'amount_label' => 'This month',
            'trial_balance' => [
                'rows' => [
                    ['code' => '1000', 'name' => 'Cash in Hand', 'type' => 'Asset', 'debit' => 500.0, 'credit' => 0.0],
                    ['code' => '4000', 'name' => 'Sales', 'type' => 'Income', 'debit' => 0.0, 'credit' => 500.0],
                ],
                'debit_total' => 500.0,
                'credit_total' => 500.0,
            ],
            'profit_and_loss' => [
                'income' => [
                    ['code' => '4000', 'name' => 'Sales', 'amount' => 500.0],
                ],
                'expenses' => [
                    ['code' => '5100', 'name' => 'Operating Expenses', 'amount' => 300.0],
                ],
                'income_total' => 500.0,
                'expense_total' => 300.0,
                'profit' => 200.0,
            ],
            'balance_sheet' => [
                'assets' => [
                    ['code' => '1000', 'name' => 'Cash in Hand', 'closing' => 500.0],
                ],
                'liabilities' => [],
                'equity' => [
                    ['code' => '3000', 'name' => 'Equity', 'closing' => 300.0],
                ],
                'asset_total' => 500.0,
                'liability_total' => 0.0,
                'equity_total' => 300.0,
                'period_profit' => 200.0,
                'profit_label' => 'Profit for the year to date',
                'financing_total' => 500.0,
            ],
        ];
    }
}
