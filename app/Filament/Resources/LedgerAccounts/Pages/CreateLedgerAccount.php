<?php

namespace App\Filament\Resources\LedgerAccounts\Pages;

use App\Filament\Resources\LedgerAccounts\LedgerAccountResource;
use App\Models\LedgerAccount;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

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

        $name = trim((string) ($data['name'] ?? ''));

        if ($name !== '') {
            $duplicate = LedgerAccount::query()
                ->where('merchant_id', $merchantId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'data.name' => 'An account named "'.$name.'" already exists. Open that account in General Ledger instead of creating a duplicate.',
                ]);
            }
        }

        return $data;
    }
}
