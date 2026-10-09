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

    /**
     * Shown next to the Per page control in the shared pagination footer.
     */
    public function getTablePaginationTotalLabel(): ?string
    {
        $query = $this->getFilteredTableQuery();

        if (! $query) {
            return null;
        }

        $table = $query->getModel()->getTable();
        $total = (float) $query->clone()->reorder()->sum("{$table}.total_amount");

        return 'Total sale: PKR '.number_format($total, 2);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('sales.create', Filament::getCurrentPanel()->getAuthGuard())),
        ];
    }
}
