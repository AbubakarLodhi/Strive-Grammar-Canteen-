<?php

namespace App\Services\Finance;

use App\Enums\FinanceDocumentStatus;
use App\Enums\LedgerAccountType;
use App\Models\JournalVoucherLine;
use App\Models\LedgerAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinancialStatements
{
    /**
     * @return array{
     *     period_label: string,
     *     as_at: string,
     *     scope: string,
     *     amount_label: string,
     *     trial_balance: array{rows: list<array<string, mixed>>, debit_total: float, credit_total: float},
     *     profit_and_loss: array{income: list<array<string, mixed>>, expenses: list<array<string, mixed>>, income_total: float, expense_total: float, profit: float},
     *     balance_sheet: array{assets: list<array<string, mixed>>, liabilities: list<array<string, mixed>>, equity: list<array<string, mixed>>, asset_total: float, liability_total: float, equity_total: float, period_profit: float, financing_total: float}
     * }
     */
    public function forPeriod(string $merchantId, int $year, ?int $month = null): array
    {
        $window = $this->periodWindow($year, $month);
        $start = $window['start'];
        $end = $window['end'];
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();

        $accounts = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->orderBy('code')
            ->get();

        $toDate = $this->postedTotals($merchantId, null, $end);
        $inPeriod = $this->postedTotals($merchantId, $start, $end);
        $yearToDate = $this->postedTotals($merchantId, $yearStart, $end);

        $trialRows = [];
        $tbDebit = 0.0;
        $tbCredit = 0.0;
        $assets = [];
        $liabilities = [];
        $equity = [];
        $incomeRows = [];
        $expenseRows = [];

        foreach ($accounts as $account) {
            $toDateLine = $toDate->get($account->id);
            $periodLine = $inPeriod->get($account->id);
            $ytdLine = $yearToDate->get($account->id);

            $lifetimeDebit = (float) ($toDateLine?->debit_total ?? 0);
            $lifetimeCredit = (float) ($toDateLine?->credit_total ?? 0);
            $closing = $this->closingBalance($account->type, (float) $account->opening_balance, $lifetimeDebit, $lifetimeCredit);
            $sides = $this->trialBalanceSides($account->type, $closing);

            $trialRows[] = [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->label(),
                'debit' => $sides['debit'],
                'credit' => $sides['credit'],
            ];
            $tbDebit += $sides['debit'];
            $tbCredit += $sides['credit'];

            $periodAmount = $this->periodAmount(
                $account->type,
                (float) ($periodLine?->debit_total ?? 0),
                (float) ($periodLine?->credit_total ?? 0),
            );
            $ytdAmount = $this->periodAmount(
                $account->type,
                (float) ($ytdLine?->debit_total ?? 0),
                (float) ($ytdLine?->credit_total ?? 0),
            );

            $row = [
                'code' => $account->code,
                'name' => $account->name,
                'closing' => $closing,
                'amount' => $periodAmount,
                'ytd' => $ytdAmount,
            ];

            match ($account->type) {
                LedgerAccountType::Asset => $assets[] = $row,
                LedgerAccountType::Liability => $liabilities[] = $row,
                LedgerAccountType::Equity => $equity[] = $row,
                LedgerAccountType::Income => $incomeRows[] = $row,
                LedgerAccountType::Expense => $expenseRows[] = $row,
            };
        }

        $incomeTotal = round(array_sum(array_column($incomeRows, 'amount')), 2);
        $expenseTotal = round(array_sum(array_column($expenseRows, 'amount')), 2);
        $profit = round($incomeTotal - $expenseTotal, 2);
        $profitToDate = round(
            array_sum(array_column($incomeRows, 'ytd')) - array_sum(array_column($expenseRows, 'ytd')),
            2
        );

        $assetTotal = round(array_sum(array_column($assets, 'closing')), 2);
        $liabilityTotal = round(array_sum(array_column($liabilities, 'closing')), 2);
        $equityTotal = round(array_sum(array_column($equity, 'closing')), 2);
        $financingTotal = round($liabilityTotal + $equityTotal + $profitToDate, 2);

        return [
            'period_label' => $window['period_label'],
            'as_at' => $end->format('d/m/Y'),
            'scope' => $window['scope'],
            'amount_label' => $window['amount_label'],
            'trial_balance' => [
                'rows' => $trialRows,
                'debit_total' => round($tbDebit, 2),
                'credit_total' => round($tbCredit, 2),
            ],
            'profit_and_loss' => [
                'income' => $incomeRows,
                'expenses' => $expenseRows,
                'income_total' => $incomeTotal,
                'expense_total' => $expenseTotal,
                'profit' => $profit,
            ],
            'balance_sheet' => [
                'assets' => $assets,
                'liabilities' => $liabilities,
                'equity' => $equity,
                'asset_total' => $assetTotal,
                'liability_total' => $liabilityTotal,
                'equity_total' => $equityTotal,
                'period_profit' => $profitToDate,
                'profit_label' => $window['scope'] === 'year' ? 'Profit for the year' : 'Profit for the year to date',
                'financing_total' => $financingTotal,
            ],
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon, period_label: string, scope: string, amount_label: string}
     */
    public function periodWindow(int $year, ?int $month = null): array
    {
        if ($month === null || $month < 1 || $month > 12) {
            return [
                'start' => Carbon::create($year, 1, 1)->startOfDay(),
                'end' => Carbon::create($year, 12, 31)->endOfDay(),
                'period_label' => (string) $year,
                'scope' => 'year',
                'amount_label' => 'Year',
            ];
        }

        $start = Carbon::create($year, $month, 1)->startOfMonth();

        return [
            'start' => $start,
            'end' => $start->copy()->endOfMonth(),
            'period_label' => $start->format('F Y'),
            'scope' => 'month',
            'amount_label' => 'This month',
        ];
    }

    public function forMonth(string $merchantId, int $year, int $month): array
    {
        return $this->forPeriod($merchantId, $year, $month);
    }

    public function closingBalance(LedgerAccountType $type, float $opening, float $debit, float $credit): float
    {
        $net = match ($type) {
            LedgerAccountType::Asset, LedgerAccountType::Expense => $opening + $debit - $credit,
            default => $opening + $credit - $debit,
        };

        return round($net, 2);
    }

    /**
     * @return array{debit: float, credit: float}
     */
    public function trialBalanceSides(LedgerAccountType $type, float $closing): array
    {
        $amount = abs($closing);

        $naturalDebit = in_array($type, [LedgerAccountType::Asset, LedgerAccountType::Expense], true);

        if ($naturalDebit) {
            return $closing >= 0
                ? ['debit' => $amount, 'credit' => 0.0]
                : ['debit' => 0.0, 'credit' => $amount];
        }

        return $closing >= 0
            ? ['debit' => 0.0, 'credit' => $amount]
            : ['debit' => $amount, 'credit' => 0.0];
    }

    public function periodAmount(LedgerAccountType $type, float $debit, float $credit): float
    {
        return $this->closingBalance($type, 0, $debit, $credit);
    }

    /**
     * @return Collection<string, object>
     */
    private function postedTotals(string $merchantId, ?Carbon $from, Carbon $to): Collection
    {
        return JournalVoucherLine::query()
            ->select('journal_voucher_lines.ledger_account_id')
            ->selectRaw('COALESCE(SUM(journal_voucher_lines.debit), 0) as debit_total')
            ->selectRaw('COALESCE(SUM(journal_voucher_lines.credit), 0) as credit_total')
            ->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_voucher_lines.journal_voucher_id')
            ->where('journal_vouchers.merchant_id', $merchantId)
            ->where('journal_vouchers.status', FinanceDocumentStatus::Posted->value)
            ->whereNull('journal_vouchers.deleted_at')
            ->whereDate('journal_vouchers.voucher_date', '<=', $to->toDateString())
            ->when(
                $from,
                fn ($query) => $query->whereDate('journal_vouchers.voucher_date', '>=', $from->toDateString())
            )
            ->groupBy('journal_voucher_lines.ledger_account_id')
            ->get()
            ->keyBy('ledger_account_id');
    }
}
