<?php

namespace App\Filament\Resources\BankReconciliations\Pages;

use App\Filament\Resources\BankReconciliations\BankReconciliationResource;
use App\Support\FinanceAccess;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankReconciliations extends ListRecords
{
    protected static string $resource = BankReconciliationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => FinanceAccess::can('bank_reconciliations', 'create')),
        ];
    }
}
