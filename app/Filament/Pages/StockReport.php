<?php

namespace App\Filament\Pages;

use App\Filament\Exports\StockReportExport;
use App\Models\Branch;
use App\Models\ProductVariant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class StockReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArchiveBox;
    protected static string|\UnitEnum|null $navigationGroup = 'Reportings';
    protected static ?int $navigationSort = 1;
    protected static ?string $title = 'Stock Report';
    protected static ?string $navigationLabel = 'Stock Report';

    protected string $view = 'filament.pages.stock-report';

    /* ============================================================
     |  EXPRESSIONS — USED ONLY FOR TABLE ROWS
     ============================================================ */

    protected function getBranchFilterValues(): array
    {
        $state = $this->getTableFilterState('branch_ids');

        $branchIds = is_array($state)
            ? ($state['values'] ?? [])
            : [];

        return collect($branchIds)
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    protected function buildBranchInScope(string $column, array $branchIds): string
    {
        if (empty($branchIds)) {
            return '';
        }

        $quotedIds = array_map(
            fn ($id) => "'" . addslashes((string) $id) . "'",
            $branchIds
        );

        return " AND {$column} IN (" . implode(', ', $quotedIds) . ')';
    }

    protected function getDateRangeFilterValues(): array
    {
        $state = $this->getTableFilterState('movement_date_range');

        return [
            'from' => $state['from_date'] ?? null,
            'to' => $state['to_date'] ?? null,
        ];
    }

    protected function purchasedExpression(?string $userId = null): string
    {
        $branchIds = $this->getBranchFilterValues();
        ['from' => $fromDate, 'to' => $toDate] = $this->getDateRangeFilterValues();

        $userScope = '';
        if ($userId) {
            $safeUserId = addslashes($userId);
            $userScope = "
                      AND pi.branch_id IN (
                          SELECT branch_id
                          FROM branch_users
                          WHERE user_id = '{$safeUserId}'
                      )
            ";
        }

        $branchScope = $this->buildBranchInScope('pi.branch_id', $branchIds);

        $fromScope = $fromDate
            ? " AND p.purchase_date >= '" . addslashes((string) $fromDate) . "'"
            : '';

        $toScope = $toDate
            ? " AND p.purchase_date <= '" . addslashes((string) $toDate) . "'"
            : '';

        return "
            COALESCE(
                (
                    SELECT SUM(piv.quantity)
                    FROM purchase_item_variants piv
                    JOIN purchase_items pi ON pi.id = piv.purchase_item_id
                    JOIN purchases p ON p.id = pi.purchase_id
                    WHERE piv.product_variant_id = product_variants.id
                      AND p.deleted_at IS NULL
                      {$userScope}
                      {$branchScope}
                      {$fromScope}
                      {$toScope}
                ),
            0)
        ";
    }

    protected function soldExpression(?string $userId = null): string
    {
        $branchIds = $this->getBranchFilterValues();
        ['from' => $fromDate, 'to' => $toDate] = $this->getDateRangeFilterValues();

        $userScope = '';
        if ($userId) {
            $safeUserId = addslashes($userId);
            $userScope = "
                      AND si.branch_id IN (
                          SELECT branch_id
                          FROM branch_users
                          WHERE user_id = '{$safeUserId}'
                      )
            ";
        }

        $branchScope = $this->buildBranchInScope('si.branch_id', $branchIds);

        $fromScope = $fromDate
            ? " AND s.sale_date >= '" . addslashes((string) $fromDate) . "'"
            : '';

        $toScope = $toDate
            ? " AND s.sale_date <= '" . addslashes((string) $toDate) . "'"
            : '';

        return "
            COALESCE(
                (
                    SELECT SUM(siv.quantity)
                    FROM sale_item_variants siv
                    JOIN sale_items si ON si.id = siv.sale_item_id
                    JOIN sales s ON s.id = si.sale_id
                    WHERE siv.product_variant_id = product_variants.id
                      AND s.deleted_at IS NULL
                      AND s.status = 'posted'
                      {$userScope}
                      {$branchScope}
                      {$fromScope}
                      {$toScope}
                ),
            0)
        ";
    }

    protected function stockExpression(?string $userId = null): string
    {
        return '(' . $this->purchasedExpression($userId) . ' - ' . $this->soldExpression($userId) . ')';
    }

    protected function stockValueExpression(?string $userId = null): string
{
    return "0";
}

    protected function lastUpdatedExpression(?string $userId = null): string
    {
        $branchIds = $this->getBranchFilterValues();
        ['from' => $fromDate, 'to' => $toDate] = $this->getDateRangeFilterValues();

        $purchaseUserScope = '';
        $saleUserScope = '';
        if ($userId) {
            $safeUserId = addslashes($userId);
            $purchaseUserScope = "
                AND pi.branch_id IN (
                    SELECT branch_id
                    FROM branch_users
                    WHERE user_id = '{$safeUserId}'
                )
            ";
            $saleUserScope = "
                AND si.branch_id IN (
                    SELECT branch_id
                    FROM branch_users
                    WHERE user_id = '{$safeUserId}'
                )
            ";
        }

        $purchaseBranchScope = $this->buildBranchInScope('pi.branch_id', $branchIds);
        $saleBranchScope = $this->buildBranchInScope('si.branch_id', $branchIds);

        $purchaseFromScope = $fromDate
            ? " AND p.purchase_date >= '" . addslashes((string) $fromDate) . "'"
            : '';

        $purchaseToScope = $toDate
            ? " AND p.purchase_date <= '" . addslashes((string) $toDate) . "'"
            : '';

        $saleFromScope = $fromDate
            ? " AND s.sale_date >= '" . addslashes((string) $fromDate) . "'"
            : '';

        $saleToScope = $toDate
            ? " AND s.sale_date <= '" . addslashes((string) $toDate) . "'"
            : '';

        return "
            NULLIF(
                GREATEST(
                    COALESCE(
                        (
                            SELECT MAX(p.purchase_date)
                            FROM purchase_item_variants piv
                            JOIN purchase_items pi ON pi.id = piv.purchase_item_id
                            JOIN purchases p ON p.id = pi.purchase_id
                            WHERE piv.product_variant_id = product_variants.id
                              AND p.deleted_at IS NULL
                              {$purchaseUserScope}
                              {$purchaseBranchScope}
                              {$purchaseFromScope}
                              {$purchaseToScope}
                        ),
                        '1970-01-01'
                    ),
                    COALESCE(
                        (
                            SELECT MAX(s.sale_date)
                            FROM sale_item_variants siv
                            JOIN sale_items si ON si.id = siv.sale_item_id
                            JOIN sales s ON s.id = si.sale_id
                            WHERE siv.product_variant_id = product_variants.id
                              AND s.deleted_at IS NULL
                              AND s.status = 'posted'
                              {$saleUserScope}
                              {$saleBranchScope}
                              {$saleFromScope}
                              {$saleToScope}
                        ),
                        '1970-01-01'
                    )
                ),
                '1970-01-01'
            )
        ";
    }

    /* ============================================================
     |  TABLE (CORRECT AS-IS)
     ============================================================ */

    public function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        $merchantId = match (true) {
            $user instanceof \App\Models\Merchant => $user->id,
            $user instanceof \App\Models\User     => $user->merchant_id,
            default                               => null,
        };

        return $table
            ->query(
                ProductVariant::query()
                    ->withoutTrashed()
                    ->where('product_variants.is_active', true)
                    ->when($merchantId, fn ($q) =>
                    $q->where('product_variants.merchant_id', $merchantId)
                    )
                    ->when($user instanceof \App\Models\User, fn ($q) =>
                    $q->whereHas('product.branches.users', fn ($u) =>
                    $u->where('users.id', $user->id)
                    )
                    )
                    ->with('product')
                    ->select('product_variants.*')
                    ->selectRaw(
                        $user instanceof \App\Models\User
                            ? $this->purchasedExpression($user->id) . ' as total_purchased'
                            : $this->purchasedExpression() . ' as total_purchased'
                    )
                    ->selectRaw(
                        $user instanceof \App\Models\User
                            ? $this->soldExpression($user->id) . ' as total_sold'
                            : $this->soldExpression() . ' as total_sold'
                    )
                    ->selectRaw(
                        $user instanceof \App\Models\User
                            ? $this->stockExpression($user->id) . ' as current_stock'
                            : $this->stockExpression() . ' as current_stock'
                    )
                    ->selectRaw(
                        $user instanceof \App\Models\User
                            ? $this->lastUpdatedExpression($user->id) . ' as last_updated'
                            : $this->lastUpdatedExpression() . ' as last_updated'
                    )
                    ->selectRaw(
                        $user instanceof \App\Models\User
                            ? $this->stockValueExpression($user->id) . ' as total_amount'
                            : $this->stockValueExpression() . ' as total_amount'
                    )
            )
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Variant')
                    ->description(fn ($record) => $record->sku)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('last_updated')
                    ->label('Last Updated')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('total_purchased')->label('Purchased')->numeric()->sortable(),
                TextColumn::make('total_sold')->label('Sold')->numeric()->sortable(),

                TextColumn::make('current_stock')
                    ->label('Stock')
                    ->badge()
                    ->icon(fn ($state) =>
                    $state <= 0 ? 'heroicon-s-x-circle'
                        : ($state <= 10 ? 'heroicon-s-exclamation-triangle'
                        : 'heroicon-s-check-circle')
                    )
                    ->color(fn ($state) =>
                    $state <= 0 ? 'danger'
                        : ($state <= 10 ? 'warning' : 'success')
                    )
                    ->sortable(),

                TextColumn::make('total_amount')
                    ->label('Total Amount')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('purchase_price')->label('Cost')->money('PKR')->toggleable(),
                TextColumn::make('selling_price')->label('Sale')->money('PKR')->toggleable(),

                IconColumn::make('is_active')
                    ->boolean()
                    ->color(fn ($state) => $state ? 'primary' : 'danger')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('branch_ids')
                    ->label('Branch')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(function () use ($user, $merchantId) {
                        if (! $merchantId) {
                            return [];
                        }

                        $query = Branch::query()
                            ->withoutTrashed()
                            ->where('merchant_id', $merchantId);

                        if ($user instanceof \App\Models\User) {
                            $query->whereHas('users', fn ($q) =>
                                $q->where('users.id', $user->id)
                            );
                        }

                        return $query
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->query(function (Builder $query, array $data) {
                        if (empty($data['values'])) {
                            return;
                        }

                        $query->whereHas('product.branches', fn (Builder $q) =>
                            $q->whereIn('branches.id', $data['values'])
                                ->whereNull('branches.deleted_at')
                        );
                    }),

                SelectFilter::make('product_variant_ids')
                    ->label('Product Variant')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(function () use ($user, $merchantId) {
                        if (! $merchantId) {
                            return [];
                        }

                        $query = ProductVariant::query()
                            ->withoutTrashed()
                            ->where('product_variants.merchant_id', $merchantId)
                            ->where('product_variants.is_active', true)
                            ->join('products', 'products.id', '=', 'product_variants.product_id')
                            ->whereNull('products.deleted_at')
                            ->select([
                                'product_variants.id',
                                'product_variants.name',
                                'product_variants.sku',
                                'products.name as product_name',
                            ]);

                        if ($user instanceof \App\Models\User) {
                            $query->whereHas('product.branches.users', fn ($u) =>
                                $u->where('users.id', $user->id)
                            );

                            $query->whereHas('product.branches', fn ($b) =>
                                $b->whereNull('branches.deleted_at')
                            );
                        }

                        $selectedBranchIds = $this->getBranchFilterValues();
                        if (! empty($selectedBranchIds)) {
                            $query->whereHas('product.branches', fn ($b) =>
                                $b->whereIn('branches.id', $selectedBranchIds)
                                    ->whereNull('branches.deleted_at')
                            );
                        }

                        return $query
                            ->orderBy('products.name')
                            ->orderBy('product_variants.name')
                            ->get()
                            ->mapWithKeys(fn (ProductVariant $variant) => [
                                $variant->id => trim(
                                    ($variant->product_name ? $variant->product_name . ' - ' : '')
                                    . ($variant->name ?: ($variant->sku ?: (string) $variant->id))
                                    . ($variant->sku ? ' (' . $variant->sku . ')' : '')
                                ),
                            ])
                            ->toArray();
                    })
                    ->query(function (Builder $query, array $data) {
                        if (empty($data['values'])) {
                            return;
                        }

                        $query->whereIn('product_variants.id', $data['values']);
                    }),

                Filter::make('movement_date_range')
                    ->label('Date Range')
                    ->schema([
                        DatePicker::make('from_date')->label('From Date'),
                        DatePicker::make('to_date')->label('To Date'),
                    ]),
            ])
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->defaultSort('current_stock', 'asc');
    }

    protected function getFilteredTableQueryWithoutPagination(): Builder
    {
        $query = clone $this->getFilteredTableQuery();
        $query->getQuery()->limit = null;
        $query->getQuery()->offset = null;
        return $query;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export to Excel')
                ->icon('heroicon-s-arrow-down-tray')
                ->visible(function () {
                    $user = auth(Filament::getCurrentPanel()->getAuthGuard())->user();
                    if (!$user) return false;
                    if ($user instanceof \App\Models\Merchant) return true;
                    return $user->hasPermissionTo('reports.view', Filament::getCurrentPanel()->getAuthGuard());
                })
                ->color('success')
                ->action(function () {
                    $baseQuery = $this->getFilteredTableQueryWithoutPagination();

                    $exportQuery = (clone $baseQuery)
                        ->with('product');

                    $totalsRow = DB::query()
                        ->fromSub($baseQuery, 'stock')
                        ->selectRaw('COALESCE(sum(total_purchased), 0) as purchased')
                        ->selectRaw('COALESCE(sum(total_sold), 0) as sold')
                        ->selectRaw('COALESCE(sum(current_stock), 0) as stock')
                        ->first();

                    $totals = [
                        'purchased' => (float) ($totalsRow->purchased ?? 0),
                        'sold'      => (float) ($totalsRow->sold ?? 0),
                        'stock'     => (float) ($totalsRow->stock ?? 0),
                    ];

                    $stats = $this->getTopStats();

                    return Excel::download(
                        new StockReportExport($exportQuery, $totals, $stats),
                        'stock-report-' . now()->format('Y-m-d_H-i-s') . '.xlsx'
                    );
                }),
        ];
    }

    /* ============================================================
     |  TOP STATS — FIXED & PORTAL-SAFE
     ============================================================ */

    public function getTopStats(): array
    {
        $user = Filament::auth()->user();
        $branchIds = $this->getBranchFilterValues();
        ['from' => $fromDate, 'to' => $toDate] = $this->getDateRangeFilterValues();
        $staffBranchIds = $user instanceof \App\Models\User
            ? $user->branches()->pluck('branches.id')
            : collect();

        $variantIds = collect();

        if ($user instanceof \App\Models\User) {
            // STAFF → only variants used in their branches
            $soldVariantIds = DB::table('sale_item_variants as sv')
                ->join('sale_items as si', 'si.id', '=', 'sv.sale_item_id')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->whereIn('si.branch_id', $staffBranchIds)
                ->where('s.status', \App\Models\Sale::STATUS_POSTED)
                ->whereNull('s.deleted_at')
                ->when(! empty($branchIds), fn ($q) => $q->whereIn('si.branch_id', $branchIds))
                ->when($fromDate, fn ($q) => $q->whereDate('s.sale_date', '>=', $fromDate))
                ->when($toDate, fn ($q) => $q->whereDate('s.sale_date', '<=', $toDate))
                ->pluck('sv.product_variant_id');

            $purchasedVariantIds = DB::table('purchase_item_variants as pv')
                ->join('purchase_items as pi', 'pi.id', '=', 'pv.purchase_item_id')
                ->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
                ->whereIn('pi.branch_id', $staffBranchIds)
                ->whereNull('p.deleted_at')
                ->when(! empty($branchIds), fn ($q) => $q->whereIn('pi.branch_id', $branchIds))
                ->when($fromDate, fn ($q) => $q->whereDate('p.purchase_date', '>=', $fromDate))
                ->when($toDate, fn ($q) => $q->whereDate('p.purchase_date', '<=', $toDate))
                ->pluck('pv.product_variant_id');

            $variantIds = $soldVariantIds
                ->merge($purchasedVariantIds)
                ->unique()
                ->values();
        } else {
            // MERCHANT → all filtered variants
            $variantIds = $this->getFilteredTableQuery()
                ->pluck('product_variants.id');
        }



        $totalProducts = $variantIds->count();

        /* PURCHASED */
        $totalPurchasedQty = DB::table('purchase_item_variants as piv')
            ->join('purchase_items as pi', 'pi.id', '=', 'piv.purchase_item_id')
            ->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->whereIn('piv.product_variant_id', $variantIds)
            ->whereNull('p.deleted_at')
            ->when($user instanceof \App\Models\User, fn ($q) =>
            $q->whereIn('pi.branch_id', $staffBranchIds)
            )
            ->when(! empty($branchIds), fn ($q) => $q->whereIn('pi.branch_id', $branchIds))
            ->when($fromDate, fn ($q) => $q->whereDate('p.purchase_date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('p.purchase_date', '<=', $toDate))
            ->sum('piv.quantity');

        $netPurchasedQty = $totalPurchasedQty;


        /* SOLD */
        $totalSoldQty = DB::table('sale_item_variants as siv')
            ->join('sale_items as si', 'si.id', '=', 'siv.sale_item_id')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->whereIn('siv.product_variant_id', $variantIds)
            ->where('s.status', \App\Models\Sale::STATUS_POSTED)
            ->whereNull('s.deleted_at')
            ->when($user instanceof \App\Models\User, fn ($q) =>
            $q->whereIn('si.branch_id', $staffBranchIds)
            )
            ->when(! empty($branchIds), fn ($q) => $q->whereIn('si.branch_id', $branchIds))
            ->when($fromDate, fn ($q) => $q->whereDate('s.sale_date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('s.sale_date', '<=', $toDate))
            ->sum('siv.quantity');

        $netSoldQty = $totalSoldQty;

        $availableStock = $netPurchasedQty - $netSoldQty;

        /* TOTAL AMOUNT (sum of table total_amount column) */
        $totalAmountRow = DB::query()
            ->fromSub($this->getFilteredTableQueryWithoutPagination(), 'stock')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total_amount')
            ->first();

        $totalAmount = (float) ($totalAmountRow->total_amount ?? 0);

        /* REVENUE */
        $totalRevenue = DB::table('sale_item_variants as siv')
            ->join('sale_items as si', 'si.id', '=', 'siv.sale_item_id')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->whereIn('siv.product_variant_id', $variantIds)
            ->where('s.status', \App\Models\Sale::STATUS_POSTED)
            ->whereNull('s.deleted_at')
            ->when($user instanceof \App\Models\User, fn ($q) =>
            $q->whereIn('si.branch_id', $staffBranchIds)
            )
            ->when(! empty($branchIds), fn ($q) => $q->whereIn('si.branch_id', $branchIds))
            ->when($fromDate, fn ($q) => $q->whereDate('s.sale_date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('s.sale_date', '<=', $toDate))
            ->sum('siv.line_total');

        $netRevenue = $totalRevenue;

        /* BUYING COST */
        $totalBuyingCost = DB::table('purchase_item_variants as piv')
            ->join('purchase_items as pi', 'pi.id', '=', 'piv.purchase_item_id')
            ->join('product_variants as pv', 'pv.id', '=', 'piv.product_variant_id')
            ->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->whereIn('pv.id', $variantIds)
            ->whereNull('p.deleted_at')
            ->when($user instanceof \App\Models\User, fn ($q) =>
            $q->whereIn('pi.branch_id', $staffBranchIds)
            )
            ->when(! empty($branchIds), fn ($q) => $q->whereIn('pi.branch_id', $branchIds))
            ->when($fromDate, fn ($q) => $q->whereDate('p.purchase_date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('p.purchase_date', '<=', $toDate))
            ->sum(DB::raw('piv.quantity * pv.purchase_price'));

        $netBuyingCost = $totalBuyingCost;

        return [
            'total_products'      => (int) $totalProducts,
            'total_purchased_qty' => (float) $netPurchasedQty,
            'total_sold_qty'      => (float) $netSoldQty,
            'available_stock'     => (float) $availableStock,
            'total_amount'        => (float) $totalAmount,
            'total_revenue'       => (float) $netRevenue,
            'avg_selling_price'   => $netSoldQty > 0 ? round($netRevenue / $netSoldQty, 2) : 0,
            'avg_buying_price'    => $netPurchasedQty > 0 ? round($netBuyingCost / $netPurchasedQty, 2) : 0,
        ];
    }
}
