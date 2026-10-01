<?php

namespace App\Filament\Resources\JournalVouchers\Pages;

use App\Enums\FinanceDocumentStatus;
use App\Filament\Resources\JournalVouchers\JournalVoucherResource;
use App\Models\Purchase;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateJournalVoucher extends CreateRecord
{
    protected static string $resource = JournalVoucherResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $lines = $data['lines'] ?? [];
        unset($data['lines']);

        $purchaseId = $data['purchase_id'] ?? null;
        unset($data['purchase_id']);

        $ledger = app(FinanceLedger::class);
        $ledger->assertBalanced($lines);

        $data['merchant_id'] = FinanceAccess::merchantId();
        $data['created_by'] = FinanceAccess::createdBy();
        $data['status'] = FinanceDocumentStatus::Draft;

        if (filled($purchaseId)) {
            $purchase = Purchase::query()
                ->where('merchant_id', $data['merchant_id'])
                ->whereKey($purchaseId)
                ->first();

            if ($purchase) {
                $existing = $ledger->findVoucherForPurchase($purchase);

                if ($existing) {
                    throw ValidationException::withMessages([
                        'data.purchase_id' => "Purchase {$purchase->purchase_no} already has voucher {$existing->voucher_no}. Saving again would double the General Ledger amount.",
                    ]);
                }

                $data['source_type'] = $purchase->getMorphClass();
                $data['source_id'] = $purchase->id;
            }
        }

        return DB::transaction(function () use ($data, $lines, $ledger) {
            $voucher = static::getModel()::create($data);

            foreach (array_values($lines) as $index => $line) {
                $voucher->lines()->create([
                    'ledger_account_id' => $line['ledger_account_id'],
                    'description' => $line['description'] ?? null,
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'sort_order' => $index + 1,
                ]);
            }

            // Post immediately so the voucher appears in General Ledger / Cash Book.
            return $ledger->postVoucher($voucher->fresh(['lines']));
        });
    }
}
