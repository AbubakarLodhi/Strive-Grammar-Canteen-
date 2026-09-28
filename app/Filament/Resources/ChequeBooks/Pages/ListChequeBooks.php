<?php

namespace App\Filament\Resources\ChequeBooks\Pages;

use App\Filament\Resources\ChequeBooks\ChequeBookResource;
use App\Support\FinanceAccess;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChequeBooks extends ListRecords
{
    protected static string $resource = ChequeBookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => FinanceAccess::can('cheque_books', 'create')),
        ];
    }
}
