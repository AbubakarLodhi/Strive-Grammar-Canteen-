<?php

namespace App\Filament\Resources\BankAccounts\Pages;

use App\Enums\LedgerAccountType;
use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Support\FinanceAccess;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBankAccount extends EditRecord
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
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['type'] = LedgerAccountType::Asset;
        $data['is_bank'] = true;
        unset($data['code']);

        if ($this->record->is_system) {
            $data['code'] = $this->record->code;
            $data['is_system'] = true;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => FinanceAccess::can('ledger_accounts', 'delete') && ! $this->record->is_system),
        ];
    }
}
