<?php

namespace App\Filament\Resources\JournalVouchers\Pages;

use App\Enums\FinanceDocumentStatus;
use App\Filament\Resources\JournalVouchers\JournalVoucherResource;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

        $ledger = app(FinanceLedger::class);
        $ledger->assertBalanced($lines);

        $data['merchant_id'] = FinanceAccess::merchantId();
        $data['created_by'] = FinanceAccess::createdBy();
        $data['status'] = FinanceDocumentStatus::Draft;

        return DB::transaction(function () use ($data, $lines) {
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

            return $voucher;
        });
    }
}
