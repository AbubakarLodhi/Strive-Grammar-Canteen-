<?php

namespace App\Filament\Resources\LedgerAccounts\Pages;

use App\Filament\Resources\LedgerAccounts\LedgerAccountResource;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Resources\Pages\CreateRecord;

class CreateLedgerAccount extends CreateRecord
{
    protected static string $resource = LedgerAccountResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $merchantId = (string) FinanceAccess::merchantId();
        $ledger = app(FinanceLedger::class);

        $data['merchant_id'] = $merchantId;
        $data['code'] = ! empty($data['is_bank'])
            ? $ledger->nextBankAccountCode($merchantId)
            : $ledger->nextLedgerAccountCode($merchantId);

        if (empty($data['is_bank'])) {
            $data['account_number'] = null;
        }

        return $data;
    }
}
