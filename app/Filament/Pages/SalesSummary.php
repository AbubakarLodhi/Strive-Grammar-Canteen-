<?php

namespace App\Filament\Pages;

use App\Filament\Exports\SalesSummaryExport;
use App\Models\Merchant;
use App\Models\Sale;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;




class SalesSummary extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CurrencyDollar;
    protected static string|\UnitEnum|null $navigationGroup = 'Reportings';
    protected static ?int $navigationSort = 4;
    protected static ?string $title = 'Sales Summary';
    protected static ?string $navigationLabel = 'Sales Summary';

    protected string $view = 'filament.pages.sales-summary';

    /* ============================================================
     |  TABLE (UNCHANGED – SALE LEVEL)
     ============================================================ */

    public function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        return $table
            ->query(function () use ($user) {
                $merchantId = match (true) {
                    $user instanceof \App\Models\Merchant => $user->id,
                    $user instanceof \App\Models\User     => $user->merchant_id,
                    default                               => null,
                };

                if (! $merchantId) {
                    return Sale::query()->withoutTrashed()->whereRaw('1 = 0');
                }

                $query = Sale::query()
                    ->withoutTrashed()
                    ->posted()
                    ->where('merchant_id', $merchantId)
                    ->with(['items.business', 'items.branch', 'returns.items'])
                    ->withCount('returns')
                    ->withSum('returns as returned_amount', 'total_amount');

                if ($user instanceof \App\Models\User) {
                    $query
                        ->whereHas('items.business.users', fn ($q) =>
                        $q->where('users.id', $user->id)
                        )
                        ->whereHas('items.branch.users', fn ($q) =>
                        $q->where('users.id', $user->id)
                        );
                }

                return $query;
            })
            ->columns([
                TextColumn::make('sale_no')->label('Sale No.')->searchable()->sortable(),
                TextColumn::make('sale_date')->label('Date')->date('d/m/Y')->sortable(),
                TextColumn::make('customer.name')->label('Customer')->searchable()->sortable()->limit(30),
                TextColumn::make('merchant.name')->label('Merchant')->toggleable()->searchable()->sortable(),

                BadgeColumn::make('branches')
                    ->label('Branch')
                    ->colors(['primary'])
                    ->getStateUsing(function ($record) {
                        return $record->items
                            ->pluck('branch.name')
                            ->filter()
                            ->unique()
                            ->values()
                            ->toArray();
                    })
                    ->formatStateUsing(function ($state) {

                        // ✅ Normalize (Filament-safe)
                        if (empty($state)) {
                            return ['-'];
                        }

                        if (is_string($state)) {
                            return $state;
                        }

                        if (! is_array($state)) {
                            return ['-'];
                        }

                        // ✅ Show max 2 badges
                        if (count($state) <= 2) {
                            return $state;
                        }

                        return array_merge(
                            array_slice($state, 0, 2),
                            ['+' . (count($state) - 2) . ' more']
                        );
                    })
                    ->toggleable(),


                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->sortable(),

                TextColumn::make('subtotal')
                    ->money('PKR')
                    ->getStateUsing(fn (Sale $record) => (float) ($record->subtotal ?? 0) + (float) $record->returns->sum('subtotal'))
                    ->toggleable(),
                TextColumn::make('discount')
                    ->label('Discount')
                    ->money('PKR')
                    ->getStateUsing(function (Sale $record) {
                        $currentDiscount = (float) $record->items->sum(function ($item) {
                            $lineTotal = (float) ($item->line_total ?? 0);
                            $discountRate = (float) ($item->discount ?? 0);

                            return $lineTotal * ($discountRate / 100);
                        });

                        $returnedDiscount = (float) $record->returns->sum('total_discount');

                        return $currentDiscount + $returnedDiscount;
                    })
                    ->toggleable(),
                TextColumn::make('tax')
                    ->label('Tax')
                    ->money('PKR')
                    ->getStateUsing(function (Sale $record) {
                        $currentTax = (float) $record->items->sum(function ($item) {
                            $lineTotal = (float) ($item->line_total ?? 0);
                            $discountRate = (float) ($item->discount ?? 0);
                            $taxRate = (float) ($item->tax ?? 0);
                            $discountAmount = $lineTotal * ($discountRate / 100);
                            $taxableAmount = $lineTotal - $discountAmount;

                            return $taxableAmount * ($taxRate / 100);
                        });

                        $returnedTax = (float) $record->returns->sum('total_tax');

                        return $currentTax + $returnedTax;
                    })
                    ->toggleable(),

                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('PKR')
                    ->getStateUsing(fn (Sale $record) => (float) ($record->total_amount ?? 0) + (float) $record->returns->sum('total_amount'))
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('payment_type')
                    ->label('Payment')
                    ->badge()
                    ->color(fn ($state) => $state === 'credit' ? 'warning' : 'success')
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->sortable(),

                BadgeColumn::make('return_status')
                    ->label('Return Status')
                    ->colors([
                        'gray' => '-',
                        'warning' => 'Partially Returned',
                        'success' => 'Returned',
                    ])
                    ->getStateUsing(function (Sale $record) {
                        if ((int) ($record->returns_count ?? 0) === 0) {
                            return '-';
                        }

                        $hasRemaining = $record->items->contains(
                            fn ($item) => (int) ($item->quantity ?? 0) > 0
                        );

                        return $hasRemaining ? 'Partially Returned' : 'Returned';
                    })
                    ->toggleable(),

                TextColumn::make('returned_quantity')
                    ->label('Returned Qty')
                    ->getStateUsing(fn (Sale $record) =>
                        (float) $record->returns->sum(fn ($return) => $return->items->sum('quantity'))
                    )
                    ->numeric(0)
                    ->toggleable(),

                TextColumn::make('sale_quantity')
                    ->label('Sale Qty')
                    ->getStateUsing(fn (Sale $record) =>
                        (float) $record->items->sum('quantity')
                        + (float) $record->returns->sum(fn ($return) => $return->items->sum('quantity'))
                    )
                    ->numeric(0)
                    ->toggleable(),

                TextColumn::make('returned_amount')
                    ->label('Returned Amount')
                    ->money('PKR')
                    ->getStateUsing(fn (Sale $record) => (float) ($record->returned_amount ?? 0))
                    ->toggleable(),

            ])
            ->filters([
                Filter::make('sale_date_range')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')
                            ->label('From'),
                        DatePicker::make('to')
                            ->label('To'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, $date) => $query->whereDate('sale_date', '>=', $date)
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn (Builder $query, $date) => $query->whereDate('sale_date', '<=', $date)
                            );
                    }),

                SelectFilter::make('payment_type')
                    ->label('Payment Type')
                    ->options([
                        'cash'   => 'Cash',
                        'credit' => 'Credit',
                    ]),

                SelectFilter::make('customer_id')
                    ->label('Customer')
                    ->options(function () {
                        $user = Filament::auth()->user();

                        $merchantId = match (true) {
                            $user instanceof \App\Models\Merchant => $user->id,
                            $user instanceof \App\Models\User     => $user->merchant_id,
                            default                               => null,
                        };

                        if (! $merchantId) {
                            return [];
                        }

                        return \App\Models\Customer::query()
                            ->withoutTrashed()
                            ->where('merchant_id', $merchantId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->query(fn (Builder $query, array $data) =>
                        filled($data['value'])
                            ? $query->where('customer_id', $data['value'])
                            : null
                    ),



                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(function () {
                        $user = Filament::auth()->user();

                        $merchantId = match (true) {
                            $user instanceof \App\Models\Merchant => $user->id,
                            $user instanceof \App\Models\User     => $user->merchant_id,
                            default                               => null,
                        };

                        if (! $merchantId) {
                            return [];
                        }

                        $query = \App\Models\Branch::query()
                            ->withoutTrashed()
                            ->where('merchant_id', $merchantId);

                        if ($user instanceof \App\Models\User) {
                            $query->whereHas('users', fn ($q) =>
                            $q->where('users.id', $user->id)
                            );
                        }

                        return $query->orderBy('name')->pluck('name', 'id')->toArray();
                    })
                    ->query(fn (Builder $query, array $data) =>
                    filled($data['value'])
                        ? $query->whereHas('items', fn ($q) =>
                    $q->where('branch_id', $data['value'])
                    )
                        : null
                    ),

            ])
            ->paginated([10, 25, 50, 100])
            ->defaultSort('sale_date', 'desc');
    }

    /* ============================================================
     |  FILTERED QUERY (NO PAGINATION)
     ============================================================ */

    protected function getFilteredTableQueryWithoutPagination(): Builder
    {
        $query = clone $this->getFilteredTableQuery();
        $query->getQuery()->limit = null;
        $query->getQuery()->offset = null;
        return $query;
    }

    /* ============================================================
     |  STATS (VARIANT-BASED — MATCHES YOUR SQL)
     ============================================================ */

    public function getSalesStats(): array
    {
        $filteredQuery = $this->getFilteredTableQueryWithoutPagination();
        $user = Filament::auth()->user();
        $merchantId = match (true) {
            $user instanceof \App\Models\Merchant => $user->id,
            $user instanceof \App\Models\User     => $user->merchant_id,
            default                               => null,
        };

        // Sale IDs in scope
        $saleIds = (clone $filteredQuery)->pluck('sales.id');

        // -----------------------------
        // TOTAL SALES
        // -----------------------------
        $totalSales = $saleIds->count();

        // -----------------------------
        // ✅ ITEM COUNT (MATCHES TABLE)
        // SUM of sale_items rows
        // -----------------------------
        $totalItemLines = DB::table('sale_items')
            ->whereIn('sale_id', $saleIds)
            ->where('quantity', '>', 0)
            ->count();

        // -----------------------------
        // ✅ QUANTITY SOLD (VARIANT BASED)
        // -----------------------------
        $totalQuantitySold = DB::table('sale_item_variants as sv')
            ->join('sale_items as si', 'si.id', '=', 'sv.sale_item_id')
            ->whereIn('si.sale_id', $saleIds)
            ->sum('sv.quantity');

        // -----------------------------
        // MONETARY TOTALS (SALE LEVEL)
        // -----------------------------
        $totalAmount   = (clone $filteredQuery)->sum('total_amount');
        $totalDiscount = DB::table('sale_items')
            ->whereIn('sale_id', $saleIds)
            ->sum(DB::raw('line_total * (discount / 100.0)'));

        $totalTax = DB::table('sale_items')
            ->whereIn('sale_id', $saleIds)
            ->sum(DB::raw('(line_total - (line_total * (discount / 100.0))) * (tax / 100.0)'));
        $totalSubtotal = (clone $filteredQuery)->sum('subtotal');

        $netAmount = $totalAmount;
        $netDiscount = $totalDiscount;
        $netTax = $totalTax;
        $netSubtotal = $totalSubtotal;
        $netQuantity = $totalQuantitySold;

        $avgSale = $totalSales > 0 ? $netAmount / $totalSales : 0;

        $openingTotalFunds = 0.0;

        if ($merchantId) {
            $merchant = Merchant::query()->find($merchantId);
            $openingTotalFunds = (float) ($merchant?->cash_in_hand ?? 0) + (float) ($merchant?->cash_in_bank ?? 0);
        }

        $salesCashEffect = (float) DB::table('sales')
            ->whereIn('id', $saleIds)
            ->selectRaw("
                COALESCE(SUM(
                    COALESCE(
                        paid_amount,
                        CASE
                            WHEN LOWER(COALESCE(payment_type, '')) = 'cash' THEN total_amount
                            ELSE 0
                        END
                    )
                ), 0) as sales_cash_effect
            ")
            ->value('sales_cash_effect');
        $currentTotalFunds = $openingTotalFunds + $salesCashEffect;

        // 🚨 HEADERS EXACTLY AS REQUIRED
        return [
            'total_sales'        => (int) $totalSales,
            'total_items_count'  => (int) $totalItemLines, // ✅ NOW MATCHES TABLE
            'total_quantity'     => (float) $netQuantity,
            'total_amount'       => (float) $netAmount,
            'total_discount'     => (float) $netDiscount,
            'total_tax'          => (float) $netTax,
            'total_subtotal'     => (float) $netSubtotal,
            'avg_sale'           => round($avgSale, 2),
            'opening_total_funds' => (float) $openingTotalFunds,
            'sales_cash_effect' => (float) $salesCashEffect,
            'current_total_funds' => (float) $currentTotalFunds,
        ];
    }

    public function getSalesReturnStats(): array
    {
        $filteredQuery = $this->getFilteredTableQueryWithoutPagination();
        $saleIds = (clone $filteredQuery)->pluck('sales.id');

        if ($saleIds->isEmpty()) {
            return [
                'total_returns' => 0,
                'total_items_count' => 0,
                'total_quantity' => 0,
                'total_amount' => 0,
                'avg_return' => 0,
            ];
        }

        $totalReturns = DB::table('sale_returns')
            ->whereIn('sale_id', $saleIds)
            ->whereNull('deleted_at')
            ->count();

        $totalItemsCount = DB::table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->whereIn('sr.sale_id', $saleIds)
            ->whereNull('sr.deleted_at')
            ->whereNull('sri.deleted_at')
            ->count();

        $totalQuantity = DB::table('sale_return_items as sri')
            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
            ->whereIn('sr.sale_id', $saleIds)
            ->whereNull('sr.deleted_at')
            ->whereNull('sri.deleted_at')
            ->sum('sri.quantity');

        $totalAmount = DB::table('sale_returns')
            ->whereIn('sale_id', $saleIds)
            ->whereNull('deleted_at')
            ->sum('total_amount');

        $avgReturn = $totalReturns > 0 ? ((float) $totalAmount / (int) $totalReturns) : 0;

        return [
            'total_returns' => (int) $totalReturns,
            'total_items_count' => (int) $totalItemsCount,
            'total_quantity' => (float) $totalQuantity,
            'total_amount' => (float) $totalAmount,
            'avg_return' => round($avgReturn, 2),
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

                    $baseQuery = $this->getFilteredTableQueryWithoutPagination();

                    $exportQuery = (clone $baseQuery)
                        ->withCount('items')
                        ->withCount('returns')
                        ->withSum('returns as returned_amount', 'total_amount')
                        ->with([
                            'merchant',
                            'customer',
                            'items.branch',
                            'returns.items',
                        ]);

                    // Sale IDs from SAME filtered dataset
                    $saleIds = (clone $baseQuery)->select('sales.id');

                    $totals = [
                        'items_count' => (int) DB::table('sale_items')
                            ->whereIn('sale_id', $saleIds)
                            ->count(),

                        'quantity' => (float) DB::table('sale_item_variants as sv')
                            ->join('sale_items as si', 'si.id', '=', 'sv.sale_item_id')
                            ->whereIn('si.sale_id', $saleIds)
                            ->sum('sv.quantity'),

                        'subtotal' => (float) (clone $baseQuery)->sum('subtotal'),
                        'discount' => (float) DB::table('sale_items')
                            ->whereIn('sale_id', $saleIds)
                            ->sum(DB::raw('line_total * (discount / 100.0)')),

                        'tax' => (float) DB::table('sale_items')
                            ->whereIn('sale_id', $saleIds)
                            ->sum(DB::raw('(line_total - (line_total * (discount / 100.0))) * (tax / 100.0)')),
                        'total'    => (float) (clone $baseQuery)->sum('total_amount'),
                        'returned_quantity' => (float) DB::table('sale_return_items as sri')
                            ->join('sale_returns as sr', 'sr.id', '=', 'sri.sale_return_id')
                            ->whereIn('sr.sale_id', $saleIds)
                            ->whereNull('sr.deleted_at')
                            ->whereNull('sri.deleted_at')
                            ->sum('sri.quantity'),
                        'returned_amount' => (float) DB::table('sale_returns')
                            ->whereIn('sale_id', $saleIds)
                            ->whereNull('deleted_at')
                            ->sum('total_amount'),
                    ];

                    $totals['subtotal'] += (float) DB::table('sale_returns')
                        ->whereIn('sale_id', $saleIds)
                        ->whereNull('deleted_at')
                        ->sum('subtotal');
                    $totals['discount'] += (float) DB::table('sale_returns')
                        ->whereIn('sale_id', $saleIds)
                        ->whereNull('deleted_at')
                        ->sum('total_discount');
                    $totals['tax'] += (float) DB::table('sale_returns')
                        ->whereIn('sale_id', $saleIds)
                        ->whereNull('deleted_at')
                        ->sum('total_tax');
                    $totals['total'] += (float) DB::table('sale_returns')
                        ->whereIn('sale_id', $saleIds)
                        ->whereNull('deleted_at')
                        ->sum('total_amount');

                    return Excel::download(
                        new SalesSummaryExport($exportQuery, $totals),
                        'sales-summary-' . now()->format('Y-m-d_H-i-s') . '.xlsx'
                    );
                }),
        ];
    }


}
