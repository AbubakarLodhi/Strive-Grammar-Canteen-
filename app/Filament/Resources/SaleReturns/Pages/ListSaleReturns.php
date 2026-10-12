<?php

namespace App\Filament\Resources\SaleReturns\Pages;

use App\Filament\Resources\SaleReturns\SaleReturnResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListSaleReturns extends ListRecords
{
    protected static string $resource = SaleReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Create Sale Return')
                ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())
                    ->user()?->hasPermissionTo('sales.update', Filament::getCurrentPanel()->getAuthGuard())
                ),
        ];
    }
}
