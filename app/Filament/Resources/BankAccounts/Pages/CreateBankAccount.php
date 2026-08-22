<?php

namespace App\Filament\Resources\BankAccounts\Pages;

use App\Enums\LedgerAccountType;
use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Resources\Pages\CreateRecord;

class CreateBankAccount extends CreateRecord
{
    protected static string $resource = BankAccountResource::class;

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
        $data['merchant_id'] = FinanceAccess::merchantId();
        $data['code'] = app(FinanceLedger::class)->nextBankAccountCode((string) $data['merchant_id']);
        $data['type'] = LedgerAccountType::Asset;
        $data['is_bank'] = true;
        $data['is_system'] = false;
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }
}
