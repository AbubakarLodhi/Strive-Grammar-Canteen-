<?php

namespace App\Filament\Resources\BankDeposits\Pages;

use App\Filament\Resources\BankDeposits\BankDepositResource;
use App\Support\FinanceAccess;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBankDeposit extends EditRecord
{
    protected static string $resource = BankDepositResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => FinanceAccess::can('bank_deposits', 'delete') && ! $this->record->isPosted()),
        ];
    }
}
