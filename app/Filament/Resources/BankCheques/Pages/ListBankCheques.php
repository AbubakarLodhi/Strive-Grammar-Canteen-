<?php

namespace App\Filament\Resources\BankCheques\Pages;

use App\Filament\Resources\BankCheques\BankChequeResource;
use App\Support\FinanceAccess;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankCheques extends ListRecords
{
    protected static string $resource = BankChequeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => FinanceAccess::can('bank_cheques', 'create')),
        ];
    }
}
