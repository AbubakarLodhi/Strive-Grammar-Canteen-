<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\HtmlString;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->description(fn (): Htmlable|string|null => $this->salesTotalDescription());
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getTablePaginationTotalLabel();
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        $query = parent::getTableQuery();

        if ($query instanceof Builder) {
            return $query
                ->posted()
                ->withExists('returns')
                ->setEagerLoads([])
                ->with([
                    'customer:id,name',
                    'merchant:id,name',
                    'createdBy:id,name',
                    'returns:id,sale_id',
                    'items:id,sale_id,product_id,business_id,branch_id,quantity,line_total,discount,tax',
                    'items.product:id,name,sku',
                    'items.business:id,name',
                    'items.branch:id,name',
                    'items.variants:id,sale_item_id,quantity',
                ]);
        }

        return $query;
    }

    /**
     * Filtered sales total. Used by page subheading, table description,
     * pagination footer (when vendor views resolve Livewire), and the
     * static post-table render hook.
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

    private function salesTotalDescription(): Htmlable|string|null
    {
        $label = $this->getTablePaginationTotalLabel();

        if (blank($label)) {
            return null;
        }

        return new HtmlString(
            '<span class="font-semibold text-gray-900 dark:text-gray-100">'.e($label).'</span>'
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('sales.create', Filament::getCurrentPanel()->getAuthGuard())),
        ];
    }
}
