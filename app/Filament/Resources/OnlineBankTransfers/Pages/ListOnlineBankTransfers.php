<?php

namespace App\Filament\Resources\OnlineBankTransfers\Pages;

use App\Filament\Resources\OnlineBankTransfers\OnlineBankTransferResource;
use App\Support\FinanceAccess;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOnlineBankTransfers extends ListRecords
{
    protected static string $resource = OnlineBankTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => FinanceAccess::can('online_transfers', 'create')),
        ];
    }
}
