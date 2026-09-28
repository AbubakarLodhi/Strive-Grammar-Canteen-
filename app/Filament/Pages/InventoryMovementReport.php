<?php

namespace App\Filament\Pages;

use App\Filament\Exports\InventoryMovementReportExport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Forms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

class InventoryMovementReport extends Page implements HasTable, HasForms
{
    use InteractsWithTable;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowTrendingUp;
    protected static string|\UnitEnum|null $navigationGroup = 'Reportings';
    protected static ?int $navigationSort = 2;
    protected static ?string $title = 'Inventory Movement Report';
    protected static ?string $navigationLabel = 'Inventory Movement';

    protected string $view = 'filament.pages.inventory-movement-report';

    /* ============================================================
     | FILTER STATE
     ============================================================ */

    public ?string $typeFilter = null;
    public ?string $directionFilter = null;
    public ?string $dateFromFilter = null;
    public ?string $dateToFilter = null;
    public ?string $branchFilter = null;

    /* ============================================================
     | FILTER FORM
     ============================================================ */

    protected function getFormSchema(): array
    {
        $user = Filament::auth()->user();
        $merchantId = match (true) {
            $user instanceof \App\Models\Merchant => $user->id,
            $user instanceof \App\Models\User     => $user->merchant_id,
            default                               => null,
        };

        return [
            Grid::make([
                'default' => 1,
                'sm' => 2,
                'xl' => 5,
            ])->schema([
                Forms\Components\Select::make('typeFilter')
                    ->label('Type')
                    ->options([
                        'Purchase' => 'Purchase',
                        'Sale'     => 'Sale',
                        'Sale Return' => 'Sale Return',
                        'Purchase Return' => 'Purchase Return',
                    ])
                    ->placeholder('All')
                    ->reactive(),

                Forms\Components\Select::make('directionFilter')
                    ->label('Direction')
                    ->options([
                        'in'  => 'In',
                        'out' => 'Out',
                    ])
                    ->placeholder('All')
                    ->reactive(),

                Forms\Components\DatePicker::make('dateFromFilter')
                    ->label('From')
                    ->displayFormat('d/m/Y')
                    ->maxDate(now())
                    ->native(false)
                    ->reactive(),

                Forms\Components\DatePicker::make('dateToFilter')
                    ->label('To')
                    ->displayFormat('d/m/Y')
                    ->minDate(fn (callable $get) => $get('dateFromFilter'))
                    ->maxDate(now())
                    ->native(false)
                    ->reactive(),

                Forms\Components\Select::make('branchFilter')
                    ->label('Branch')
                    ->placeholder('All')
                    ->searchable()
                    ->preload()
                    ->options(function () use ($user, $merchantId) {
                        if (! $merchantId) {
                            return [];
                        }

                        $query = \App\Models\Branch::query()->withoutTrashed()->where('merchant_id', $merchantId);

                        if ($user instanceof \App\Models\User) {
                            $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
                        }

                        return $query->orderBy('name')->pluck('name', 'id')->toArray();
                    })
                    ->reactive(),
            ]),
        ];
    }

    /* ============================================================
     | TABLE
     ============================================================ */

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int|string $page = 1, int|string|null $recordsPerPage = null) => $this->getPaginatedRecords($page, $recordsPerPage))
            ->columns([
                TextColumn::make('date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('type')
                    ->badge()
                    ->color(fn ($state) => in_array($state, ['Purchase', 'Sale Return'], true) ? 'success' : 'danger'),

                TextColumn::make('reference')
                    ->label('Reference No.')
                    ->toggleable()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_by')
                    ->label('Created By')
                    ->toggleable()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product_name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('variant_name')
                    ->label('Variant')
                    ->toggleable()
                    ->searchable(),

                TextColumn::make('product_sku')
                    ->label('SKU')
                    ->toggleable(),

                TextColumn::make('quantity')
                    ->toggleable()
                    ->numeric()
                    ->sortable(),

                TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->money('PKR')
                    ->toggleable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('PKR')
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('direction')
                    ->toggleable()
                    ->badge()
                    ->getStateUsing(fn ($record) =>
                    ($record['direction'] ?? null) === 'in' ? 'In' : 'Out'
                    )
                    ->color(fn ($state) =>
                    $state === 'In' ? 'success' : 'danger'
                    ),


            ])
            ->defaultSort('date', 'desc')
            ->paginated([10,25, 50, 100]);
    }

    /* ============================================================
     | DATA SOURCE (COLLECTION)
     ============================================================ */

    protected function getRecords(): Collection
    {
        $user = Filament::auth()->user();
        $merchantId = match (true) {
            $user instanceof \App\Models\Merchant => $user->id,
            $user instanceof \App\Models\User     => $user->merchant_id,
            default                               => null,
        };

        $purchaseRows = \App\Models\Purchase::query()
            ->withoutTrashed()
            ->with([
                'createdBy',
                'items.variants.variant.product',
                'items.business.users',
                'items.branch.users',
            ])
            ->whereHas('items.variants.variant.product', fn (Builder $q) => $q->withoutTrashed())
            ->when($merchantId, fn ($q) =>
            $q->where('merchant_id', $merchantId)
            )
            ->when($this->dateFromFilter, fn ($q, $date) =>
                $q->whereDate('purchase_date', '>=', $date)
            )
            ->when($this->dateToFilter, fn ($q, $date) =>
                $q->whereDate('purchase_date', '<=', $date)
            )
            ->when($this->branchFilter, fn ($q, $branchId) =>
                $q->whereHas('items', fn ($itemQ) => $itemQ->where('branch_id', $branchId))
            )
            ->when($user instanceof \App\Models\User, fn ($q) =>
            $q->whereHas('items.business.users', fn ($u) =>
            $u->where('users.id', $user->id)
            )
                ->whereHas('items.branch.users', fn ($u) =>
                $u->where('users.id', $user->id)
                )
            )
            ->get()
            ->flatMap(function ($purchase) {
                return $purchase->items->flatMap(function ($item) use ($purchase) {
                    return $item->variants->map(function ($variantRow) use ($purchase, $item) {
                        $variant = $variantRow->variant;
                        $product = $variant?->product;
                        if (! $variant || ! $product) {
                            return null;
                        }

                        return [
                            'id'            => 'purchase-var-' . $variantRow->id,
                            'date'          => $purchase->purchase_date,
                            'type'          => 'Purchase',
                            'reference'     => $purchase->purchase_no,
                            'created_by'    => $purchase->createdBy?->name ?? '-',

                            // PRODUCT (BLUEPRINT)
                            'product_name'  => $product->name,

                            // VARIANT (STOCK UNIT)
                            'variant_name'  => $variant->name,
                            'product_sku'   => $variant->sku,

                            'quantity'      => $variantRow->quantity,
                            'unit_price'    => $variantRow->unit_price,
                            'total'         => $variantRow->line_total,

                            'direction'     => 'in',
                        ];
                    })->filter();
                });
            });




        // Sales (OUT)

        $saleRows = \App\Models\Sale::query()
            ->withoutTrashed()
            ->posted()
            ->with([
                'createdBy',
                'items.variants.variant.product',
                'items.business.users',
                'items.branch.users',
            ])
            ->whereHas('items.variants.variant.product', fn (Builder $q) => $q->withoutTrashed())
            ->when($merchantId, fn ($q) =>
            $q->where('merchant_id', $merchantId)
            )
            ->when($this->dateFromFilter, fn ($q, $date) =>
                $q->whereDate('sale_date', '>=', $date)
            )
            ->when($this->dateToFilter, fn ($q, $date) =>
                $q->whereDate('sale_date', '<=', $date)
            )
            ->when($this->branchFilter, fn ($q, $branchId) =>
                $q->whereHas('items', fn ($itemQ) => $itemQ->where('branch_id', $branchId))
            )
            ->when($user instanceof \App\Models\User, fn ($q) =>
            $q->whereHas('items.business.users', fn ($u) =>
            $u->where('users.id', $user->id)
            )
                ->whereHas('items.branch.users', fn ($u) =>
                $u->where('users.id', $user->id)
                )
            )
            ->get()
            ->flatMap(function ($sale) {
                return $sale->items->flatMap(function ($item) use ($sale) {
                    return $item->variants->map(function ($variantRow) use ($sale, $item) {
                        $variant = $variantRow->variant;
                        $product = $variant?->product;
                        if (! $variant || ! $product) {
                            return null;
                        }

                        return [
                            'id'            => 'sale-var-' . $variantRow->id,
                            'date'          => $sale->sale_date,
                            'type'          => 'Sale',
                            'reference'     => $sale->sale_no,
                            'created_by'    => $sale->createdBy?->name ?? '-',

                            'product_name'  => $product->name,
                            'variant_name'  => $variant->name,
                            'product_sku'   => $variant->sku,

                            'quantity'      => $variantRow->quantity,
                            'unit_price'    => $variantRow->unit_price,
                            'total'         => $variantRow->line_total,

                            'direction'     => 'out',
                        ];
                    })->filter();
                });
            });



        $returnRows = \App\Models\SaleReturn::query()
            ->with([
                'items.variants.variant.product',
                'items.product',
                'items.business.users',
                'items.branch.users',
            ])
            ->whereHas('sale')
            ->when($merchantId, fn ($q) =>
            $q->where('merchant_id', $merchantId)
            )
            ->when($this->dateFromFilter, fn ($q, $date) =>
                $q->whereDate('return_date', '>=', $date)
            )
            ->when($this->dateToFilter, fn ($q, $date) =>
                $q->whereDate('return_date', '<=', $date)
            )
            ->when($this->branchFilter, fn ($q, $branchId) =>
                $q->whereHas('items', fn ($itemQ) => $itemQ->where('branch_id', $branchId))
            )
            ->when($user instanceof \App\Models\User, fn ($q) =>
            $q->whereHas('items.business.users', fn ($u) =>
            $u->where('users.id', $user->id)
            )
                ->whereHas('items.branch.users', fn ($u) =>
                $u->where('users.id', $user->id)
                )
            )
            ->get()
            ->flatMap(function ($return) {
                return $return->items->flatMap(function ($item) use ($return) {
                    if ($item->variants->isEmpty()) {
                        $product = $item->product;
                        if (! $product || (method_exists($product, 'trashed') && $product->trashed())) {
                            return [];
                        }

                        return [[
                            'id'            => 'sale-return-item-' . $item->id,
                            'date'          => $return->return_date,
                            'type'          => 'Sale Return',
                            'reference'     => $return->return_no,
                            'created_by'    => '-',
                            'product_name'  => $product?->name ?? '-',
                            'variant_name'  => '-',
                            'product_sku'   => '-',
                            'quantity'      => $item->quantity,
                            'unit_price'    => $item->unit_price,
                            'total'         => $item->line_total,
                            'direction'     => 'in',
                        ]];
                    }

                    return $item->variants->map(function ($variantRow) use ($return, $item) {
                        $variant = $variantRow->variant;
                        $product = $variant?->product ?? $item->product;
                        if (! $product || (method_exists($product, 'trashed') && $product->trashed())) {
                            return null;
                        }

                        return [
                            'id'            => 'sale-return-var-' . $variantRow->id,
                            'date'          => $return->return_date,
                            'type'          => 'Sale Return',
                            'reference'     => $return->return_no,
                            'created_by'    => '-',
                            'product_name'  => $product?->name ?? '-',
                            'variant_name'  => $variant?->name ?? '-',
                            'product_sku'   => $variant?->sku ?? '-',
                            'quantity'      => $variantRow->quantity,
                            'unit_price'    => $variantRow->unit_price,
                            'total'         => $variantRow->line_total,
                            'direction'     => 'in',
                        ];
                    })->filter();
                });
            });

        $purchaseReturnRows = \App\Models\PurchaseReturn::query()
            ->with([
                'items.variants.variant.product',
                'items.product',
                'items.business.users',
                'items.branch.users',
            ])
            ->whereHas('purchase')
            ->when($merchantId, fn ($q) =>
            $q->where('merchant_id', $merchantId)
            )
            ->when($this->dateFromFilter, fn ($q, $date) =>
                $q->whereDate('return_date', '>=', $date)
            )
            ->when($this->dateToFilter, fn ($q, $date) =>
                $q->whereDate('return_date', '<=', $date)
            )
            ->when($this->branchFilter, fn ($q, $branchId) =>
                $q->whereHas('items', fn ($itemQ) => $itemQ->where('branch_id', $branchId))
            )
            ->when($user instanceof \App\Models\User, fn ($q) =>
            $q->whereHas('items.business.users', fn ($u) =>
            $u->where('users.id', $user->id)
            )
                ->whereHas('items.branch.users', fn ($u) =>
                $u->where('users.id', $user->id)
                )
            )
            ->get()
            ->flatMap(function ($return) {
                return $return->items->flatMap(function ($item) use ($return) {
                    if ($item->variants->isEmpty()) {
                        $product = $item->product;
                        if (! $product || (method_exists($product, 'trashed') && $product->trashed())) {
                            return [];
                        }

                        return [[
                            'id'            => 'purchase-return-item-' . $item->id,
                            'date'          => $return->return_date,
                            'type'          => 'Purchase Return',
                            'reference'     => $return->return_no,
                            'created_by'    => '-',
                            'product_name'  => $product?->name ?? '-',
                            'variant_name'  => '-',
                            'product_sku'   => '-',
                            'quantity'      => $item->quantity,
                            'unit_price'    => $item->unit_price,
                            'total'         => $item->line_total,
                            'direction'     => 'out',
                        ]];
                    }

                    return $item->variants->map(function ($variantRow) use ($return, $item) {
                        $variant = $variantRow->variant;
                        $product = $variant?->product ?? $item->product;
                        if (! $product || (method_exists($product, 'trashed') && $product->trashed())) {
                            return null;
                        }

                        return [
                            'id'            => 'purchase-return-var-' . $variantRow->id,
                            'date'          => $return->return_date,
                            'type'          => 'Purchase Return',
                            'reference'     => $return->return_no,
                            'created_by'    => '-',
                            'product_name'  => $product?->name ?? '-',
                            'variant_name'  => $variant?->name ?? '-',
                            'product_sku'   => $variant?->sku ?? '-',
                            'quantity'      => $variantRow->quantity,
                            'unit_price'    => $variantRow->unit_price,
                            'total'         => $variantRow->line_total,
                            'direction'     => 'out',
                        ];
                    })->filter();
                });
            });

        $records = $purchaseRows
            ->concat($saleRows)
            ->concat($returnRows)
            ->concat($purchaseReturnRows)
            ->sortByDesc('date')
            ->values();


        // APPLY FILTERS MANUALLY
        if ($this->typeFilter) {
            $records = $records->where('type', $this->typeFilter)->values();
        }

        if ($this->directionFilter) {
            $records = $records->where('direction', $this->directionFilter)->values();
        }

        return $records;
    }

    protected function getPaginatedRecords(int|string $page = 1, int|string|null $recordsPerPage = null): LengthAwarePaginator|Collection
    {
        $records = $this->getRecords();

        if ($recordsPerPage === null || $recordsPerPage === 'all') {
            return $records;
        }

        $page = max(1, (int) $page);
        $perPage = max(1, (int) $recordsPerPage);
        $results = $records->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $results,
            $records->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /* ============================================================
     | STATS (FILTER AWARE)
     ============================================================ */

    public function getMovementStats(): array
    {
        $records = $this->getRecords();

            $in  = $records->where('direction', 'in')->sum('quantity');
            $out = $records->where('direction', 'out')->sum('quantity');

        return [
            'in'  => (float) $in,
            'out' => (float) $out,
            'net' => (float) ($in - $out),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export to Excel')
                ->icon('heroicon-s-arrow-down-tray')
                ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('reports.view', Filament::getCurrentPanel()->getAuthGuard()))
                ->color('success')
                ->action(function () {
                    $records = $this->getRecords();

                    $totals = [
                        'quantity' => (float) $records->sum('quantity'),
                        'total'    => (float) $records->sum('total'),
                    ];

                    $stats = $this->getMovementStats();

                    return Excel::download(
                        new InventoryMovementReportExport($records, $totals, $stats),
                        'inventory-movement-report-' . now()->format('Y-m-d_H-i-s') . '.xlsx'
                    );
                }),
        ];
    }
}
