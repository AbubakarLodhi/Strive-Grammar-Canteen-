<?php

namespace App\Services\Finance;

use App\Enums\BankReconciliationStatus;
use App\Enums\FinanceDocumentStatus;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationItem;
use App\Models\JournalVoucherLine;
use Illuminate\Validation\ValidationException;

class BankReconciliationService
{
    public function __construct(
        private FinanceLedger $ledger,
    ) {}

    public function seedLines(BankReconciliation $reconciliation): void
    {
        $lineIds = JournalVoucherLine::query()
            ->where('ledger_account_id', $reconciliation->bank_account_id)
            ->whereHas('journalVoucher', function ($query) use ($reconciliation): void {
                $query->where('merchant_id', $reconciliation->merchant_id)
                    ->where('status', FinanceDocumentStatus::Posted->value)
                    ->whereDate('voucher_date', '>=', $reconciliation->period_from)
                    ->whereDate('voucher_date', '<=', $reconciliation->period_to);
            })
            ->pluck('id');

        foreach ($lineIds as $lineId) {
            BankReconciliationItem::query()->firstOrCreate(
                [
                    'bank_reconciliation_id' => $reconciliation->id,
                    'journal_voucher_line_id' => $lineId,
                ],
                [
                    'is_matched' => false,
                ]
            );
        }
    }

    public function toggleMatch(BankReconciliationItem $item, bool $matched): BankReconciliationItem
    {
        if ($item->reconciliation?->isCompleted()) {
            throw ValidationException::withMessages([
                'status' => 'Completed reconciliations cannot be changed.',
            ]);
        }

        $item->forceFill(['is_matched' => $matched])->save();

        return $item->fresh() ?? $item;
    }

    /**
     * @return array{matched_net: float, expected_closing: float, difference: float}
     */
    public function totals(BankReconciliation $reconciliation): array
    {
        $matched = $reconciliation->items()
            ->where('is_matched', true)
            ->with('journalVoucherLine')
            ->get();

        $matchedNet = round($matched->sum(function (BankReconciliationItem $item): float {
            $line = $item->journalVoucherLine;

            return (float) ($line?->debit ?? 0) - (float) ($line?->credit ?? 0);
        }), 2);

        $expectedClosing = round((float) $reconciliation->statement_opening + $matchedNet, 2);
        $difference = round($expectedClosing - (float) $reconciliation->statement_closing, 2);

        return [
            'matched_net' => $matchedNet,
            'expected_closing' => $expectedClosing,
            'difference' => $difference,
        ];
    }

    public function complete(BankReconciliation $reconciliation): BankReconciliation
    {
        if ($reconciliation->isCompleted()) {
            return $reconciliation;
        }

        $totals = $this->totals($reconciliation);

        if (abs($totals['difference']) > 0.009) {
            throw ValidationException::withMessages([
                'statement_closing' => 'Difference must be zero before completing. Current difference: '
                    .number_format($totals['difference'], 2),
            ]);
        }

        $reconciliation->forceFill([
            'status' => BankReconciliationStatus::Completed,
            'completed_at' => now(),
        ])->save();

        return $reconciliation->fresh() ?? $reconciliation;
    }

    public function nextReconNo(string $merchantId): string
    {
        return $this->ledger->nextReconNo($merchantId);
    }
}
