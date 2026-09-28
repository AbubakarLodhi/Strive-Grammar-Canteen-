<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    protected function getTableQuery(): Builder|Relation|null
    {
        $query = parent::getTableQuery();

        if ($query instanceof Builder) {
            return $query->posted();
        }

        return $query;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('sales.create', Filament::getCurrentPanel()->getAuthGuard())),
        ];
    }
}
