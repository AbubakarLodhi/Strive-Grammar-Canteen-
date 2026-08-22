<?php

namespace App\Filament\Resources\BankDeposits\Pages;

use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Filament\Resources\BankDeposits\BankDepositResource;
use App\Support\FinanceAccess;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankDeposits extends ListRecords
{
    protected static string $resource = BankDepositResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addBankAccount')
                ->label('Add bank account')
                ->icon('heroicon-o-plus')
                ->color('gray')
                ->url(BankAccountResource::getUrl('create'))
                ->visible(fn (): bool => FinanceAccess::can('ledger_accounts', 'create')),
            CreateAction::make()
                ->visible(fn (): bool => FinanceAccess::can('bank_deposits', 'create')),
        ];
    }
}
