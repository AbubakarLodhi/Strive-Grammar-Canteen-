<?php

namespace App\Filament\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class FinancialReportsExport implements WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $statements
     */
    public function __construct(private array $statements) {}

    /**
     * @return list<FinancialStatementSheet>
     */
    public function sheets(): array
    {
        $tb = $this->statements['trial_balance'];
        $pl = $this->statements['profit_and_loss'];
        $bs = $this->statements['balance_sheet'];

        $trialRows = [];
        foreach ($tb['rows'] as $row) {
            $trialRows[] = [
                $row['name'],
                $row['type'],
                $row['debit'] ?: null,
                $row['credit'] ?: null,
            ];
        }
        $trialRows[] = ['Total', '', $tb['debit_total'], $tb['credit_total']];

        $amountLabel = $this->statements['amount_label'] ?? 'Amount';
        $plRows = [['Income', '']];
        foreach ($pl['income'] as $row) {
            $plRows[] = [$row['name'], $row['amount']];
        }
        $plRows[] = ['Total income', $pl['income_total']];
        $plRows[] = ['Expenses', ''];
        foreach ($pl['expenses'] as $row) {
            $plRows[] = [$row['name'], $row['amount']];
        }
        $plRows[] = ['Total expenses', $pl['expense_total']];
        $plRows[] = [$pl['profit'] >= 0 ? 'Net profit' : 'Net loss', $pl['profit']];

        $bsRows = [['Assets', '']];
        foreach ($bs['assets'] as $row) {
            $bsRows[] = [$row['name'], $row['closing']];
        }
        $bsRows[] = ['Total assets', $bs['asset_total']];
        $bsRows[] = ['Liabilities', ''];
        foreach ($bs['liabilities'] as $row) {
            $bsRows[] = [$row['name'], $row['closing']];
        }
        $bsRows[] = ['Total liabilities', $bs['liability_total']];
        $bsRows[] = ['Equity', ''];
        foreach ($bs['equity'] as $row) {
            $bsRows[] = [$row['name'], $row['closing']];
        }
        $profitLabel = $bs['profit_label'] ?? 'Profit for the year';
        if ($bs['period_profit'] < 0) {
            $profitLabel = str_replace('Profit', 'Loss', $profitLabel);
        }
        $bsRows[] = [$profitLabel, $bs['period_profit']];
        $bsRows[] = ['Total liabilities and equity', $bs['financing_total']];

        $period = $this->statements['period_label'].' as at '.$this->statements['as_at'];

        return [
            new FinancialStatementSheet(
                'Trial Balance',
                ['Account', 'Type', 'Debit', 'Credit'],
                array_merge([[$period, '', '', '']], $trialRows),
            ),
            new FinancialStatementSheet(
                'Profit and Loss',
                ['Account', $amountLabel],
                array_merge([[$period, '']], $plRows),
            ),
            new FinancialStatementSheet(
                'Balance Sheet',
                ['Account', 'Amount'],
                array_merge([[$period, '']], $bsRows),
            ),
        ];
    }
}
