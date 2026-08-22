<?php

namespace App\Filament\Widgets;

use App\Models\CashFlow;
use App\Models\Expense;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\Payroll;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use App\Services\Finance\FinanceLedger;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportsStatsWidget extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.reports-stats-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    /** @var list<string> */
    public array $overviewModules = [];

    /**
     * @return array<string, string>
     */
    public static function overviewModuleOptions(): array
    {
        return [
            'sales' => 'Sales',
            'purchases' => 'Purchases',
            'profit_loss' => 'Profit & Loss',
            'stock' => 'Stock',
            'inventory_movement' => 'Inventory Movement',
            'expenses' => 'Expenses',
            'funds' => 'Total Funds',
            'cash_flow' => 'Cash Flow',
        ];
    }

    /**
     * @return list<string>
     */
    protected function selectedOverviewModules(): array
    {
        $validKeys = array_keys(self::overviewModuleOptions());

        $selected = collect($this->overviewModules)
            ->filter(fn (mixed $key): bool => is_string($key) && in_array($key, $validKeys, true))
            ->values()
            ->all();

        if ($selected === []) {
            return $validKeys;
        }

        return array_values(array_filter(
            $validKeys,
            fn (string $key): bool => in_array($key, $selected, true),
        ));
    }

    protected function overviewShowsModule(string $module): bool
    {
        return in_array($module, $this->selectedOverviewModules(), true);
    }

    /**
     * @return list<array{modules: list<string>, classes: string}>
     */
    protected function overviewLayoutRows(): array
    {
        $modules = $this->selectedOverviewModules();
        $count = count($modules);

        if ($count === 0) {
            return [];
        }

        if ($count <= 3) {
            return [[
                'modules' => $modules,
                'classes' => $this->overviewRowGridClasses($count),
            ]];
        }

        $rows = [];
        $chunks = array_chunk($modules, 3);

        foreach ($chunks as $index => $chunk) {
            $isLast = $index === count($chunks) - 1;
            $chunkCount = count($chunk);

            $rows[] = [
                'modules' => $chunk,
                'classes' => ($isLast && $chunkCount < 3)
                    ? $this->overviewRowGridClasses($chunkCount)
                    : 'grid grid-cols-1 gap-6 lg:grid-cols-3',
            ];
        }

        return $rows;
    }

    protected function overviewRowGridClasses(int $count): string
    {
        return match ($count) {
            1 => 'grid grid-cols-1 gap-6 max-w-md mx-auto',
            2 => 'grid grid-cols-1 gap-6 sm:grid-cols-2 max-w-4xl mx-auto',
            default => 'grid grid-cols-1 gap-6 lg:grid-cols-3',
        };
    }

    protected function overviewModulesFilterLabel(): string
    {
        $selected = collect($this->overviewModules)
            ->filter(fn (mixed $key): bool => is_string($key) && array_key_exists($key, self::overviewModuleOptions()))
            ->values();

        if ($selected->isEmpty()) {
            return 'All modules';
        }

        if ($selected->count() === 1) {
            return self::overviewModuleOptions()[$selected->first()];
        }

        return $selected->count().' modules';
    }

    public function clearOverviewModules(): void
    {
        $this->overviewModules = [];
    }

    protected function getViewData(): array
    {
        return [
            'sales' => $this->getSalesStats(),
            'purchases' => $this->getPurchaseStats(),
            'expenses' => $this->getExpenseStats(),
            'funds' => $this->getFundStats(),
            'profitLoss' => $this->getProfitLossStats(),
            'stock' => $this->getStockStats(),
            'returns' => $this->getReturnStats(),
            'trend' => $this->getTrendData(),
            'leaders' => $this->getLeaderboardStats(),
            'credit' => $this->getCreditStats(),
            'filterPeriodLabel' => $this->filterPeriodLabel(),
            'overviewModuleOptions' => self::overviewModuleOptions(),
            'overviewLayoutRows' => $this->overviewLayoutRows(),
            'overviewModulesFilterLabel' => $this->overviewModulesFilterLabel(),
        ];
    }

    protected function authContext(): array
    {
        $user = Filament::auth()->user();

        $merchantId = match (true) {
            $user instanceof Merchant => $user->id,
            $user instanceof User => $user->merchant_id,
            default => null,
        };

        return [$user, $merchantId];
    }

    protected function filters(): array
    {
        return [
            'business_id' => $this->pageFilters['business_id'] ?? null,
            'branch_id' => $this->pageFilters['branch_id'] ?? null,
            'product_variant_ids' => collect($this->pageFilters['product_variant_ids'] ?? [])
                ->filter(fn ($id) => filled($id))
                ->map(fn ($id) => (string) $id)
                ->values()
                ->all(),
            'date_from' => $this->pageFilters['date_from'] ?? null,
            'date_to' => $this->pageFilters['date_to'] ?? null,
        ];
    }

    protected function filterPeriodLabel(): string
    {
        $filters = $this->filters();
        $from = filled($filters['date_from']) ? Carbon::parse($filters['date_from'])->format('d M Y') : null;
        $to = filled($filters['date_to']) ? Carbon::parse($filters['date_to'])->format('d M Y') : null;

        return match (true) {
            filled($from) && filled($to) => "{$from} - {$to}",
            filled($from) => "From {$from}",
            filled($to) => "Until {$to}",
            default => 'All time',
        };
    }

    protected function staffAssignments(User $user): array
    {
        return [
            'business_ids' => $user->businesses()->pluck('businesses.id'),
            'branch_ids' => $user->branches()->pluck('branches.id'),
        ];
    }

    protected function sqlInScope(string $column, array $values): string
    {
        $values = collect($values)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => "'".addslashes((string) $value)."'")
            ->values()
            ->all();

        if (empty($values)) {
            return '';
        }

        return " AND {$column} IN (".implode(', ', $values).')';
    }

    protected function stockSoldExpression(?string $merchantId, array $filters, array $staffBusinessIds = [], array $staffBranchIds = []): string
    {
        if (! $merchantId) {
            return '0';
        }

        $merchantScope = " AND s.merchant_id = '".addslashes($merchantId)."'";
        $businessScope = filled($filters['business_id'] ?? null)
            ? " AND si.business_id = '".addslashes((string) $filters['business_id'])."'"
            : '';
        $branchScope = filled($filters['branch_id'] ?? null)
            ? " AND si.branch_id = '".addslashes((string) $filters['branch_id'])."'"
            : '';
        $staffBusinessScope = $this->sqlInScope('si.business_id', $staffBusinessIds);
        $staffBranchScope = $this->sqlInScope('si.branch_id', $staffBranchIds);
        $fromScope = filled($filters['date_from'] ?? null)
            ? " AND s.sale_date >= '".addslashes((string) $filters['date_from'])."'"
            : '';
        $toScope = filled($filters['date_to'] ?? null)
            ? " AND s.sale_date <= '".addslashes((string) $filters['date_to'])."'"
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
                      {$merchantScope}
                      {$businessScope}
                      {$branchScope}
                      {$staffBusinessScope}
                      {$staffBranchScope}
                      {$fromScope}
                      {$toScope}
                ),
                0
            )
        ";
    }

    protected function stockValueExpression(?string $merchantId, array $filters, array $staffBusinessIds = [], array $staffBranchIds = []): string
    {
        // MySQL does not support CTEs inside subqueries
        // Return simple purchase cost calculation instead
        if (! $merchantId) {
            return '0';
        }

        $merchantScope = " AND p.merchant_id = '".addslashes($merchantId)."'";
        $businessScope = filled($filters['business_id'] ?? null)
            ? " AND pi.business_id = '".addslashes((string) $filters['business_id'])."'"
            : '';
        $branchScope = filled($filters['branch_id'] ?? null)
            ? " AND pi.branch_id = '".addslashes((string) $filters['branch_id'])."'"
            : '';
        $staffBusinessScope = $this->sqlInScope('pi.business_id', $staffBusinessIds);
        $staffBranchScope = $this->sqlInScope('pi.branch_id', $staffBranchIds);
        $fromScope = filled($filters['date_from'] ?? null)
            ? " AND p.purchase_date >= '".addslashes((string) $filters['date_from'])."'"
            : '';
        $toScope = filled($filters['date_to'] ?? null)
            ? " AND p.purchase_date <= '".addslashes((string) $filters['date_to'])."'"
            : '';

        return "
        COALESCE(
            (
                SELECT SUM(piv.quantity * piv.unit_price)
                FROM purchase_item_variants piv
                JOIN purchase_items pi ON pi.id = piv.purchase_item_id
                JOIN purchases p ON p.id = pi.purchase_id
                WHERE piv.product_variant_id = product_variants.id
                  AND p.deleted_at IS NULL
                  {$merchantScope}
                  {$businessScope}
                  {$branchScope}
                  {$staffBusinessScope}
                  {$staffBranchScope}
                  {$fromScope}
                  {$toScope}
            ),
            0
        )
    ";
    }

    protected function salesBaseQuery($user, string $merchantId): EloquentBuilder
    {
        $filters = $this->filters();

        $query = Sale::query()
            ->withoutTrashed()
            ->where('merchant_id', $merchantId)
            ->when(
                $filters['business_id'],
                fn (EloquentBuilder $query, $businessId) => $query->whereHas('items', fn ($q) => $q->where('sale_items.business_id', $businessId)
                ),
            )
            ->when(
                $filters['branch_id'],
                fn (EloquentBuilder $query, $branchId) => $query->whereHas('items', fn ($q) => $q->where('sale_items.branch_id', $branchId)
                ),
            )
            ->when(
                ! empty($filters['product_variant_ids']),
                fn (EloquentBuilder $query) => $query->whereHas('items.variants', fn ($q) => $q->whereIn('sale_item_variants.product_variant_id', $filters['product_variant_ids'])
                ),
            )
            ->when(
                $filters['date_from'],
                fn (EloquentBuilder $query, $date) => $query->whereDate('sale_date', '>=', $date),
            )
            ->when(
                $filters['date_to'],
                fn (EloquentBuilder $query, $date) => $query->whereDate('sale_date', '<=', $date),
            );

        if ($user instanceof User) {
            $assignments = $this->staffAssignments($user);
            $businessIds = $assignments['business_ids'];
            $branchIds = $assignments['branch_ids'];

            if ($businessIds->isEmpty() || $branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('items', fn ($q) => $q
                    ->whereIn('sale_items.business_id', $businessIds)
                    ->whereIn('sale_items.branch_id', $branchIds)
                );
            }
        }

        return $query;
    }

    protected function purchaseBaseQuery($user, string $merchantId): EloquentBuilder
    {
        $filters = $this->filters();

        $query = Purchase::query()
            ->withoutTrashed()
            ->where('merchant_id', $merchantId)
            ->when(
                $filters['business_id'],
                fn (EloquentBuilder $query, $businessId) => $query->whereHas('items', fn ($q) => $q->where('purchase_items.business_id', $businessId)
                ),
            )
            ->when(
                $filters['branch_id'],
                fn (EloquentBuilder $query, $branchId) => $query->whereHas('items', fn ($q) => $q->where('purchase_items.branch_id', $branchId)
                ),
            )
            ->when(
                ! empty($filters['product_variant_ids']),
                fn (EloquentBuilder $query) => $query->whereHas('items.variants', fn ($q) => $q->whereIn('purchase_item_variants.product_variant_id', $filters['product_variant_ids'])
                ),
            )
            ->when(
                $filters['date_from'],
                fn (EloquentBuilder $query, $date) => $query->whereDate('purchase_date', '>=', $date),
            )
            ->when(
                $filters['date_to'],
                fn (EloquentBuilder $query, $date) => $query->whereDate('purchase_date', '<=', $date),
            );

        if ($user instanceof User) {
            $assignments = $this->staffAssignments($user);
            $businessIds = $assignments['business_ids'];
            $branchIds = $assignments['branch_ids'];

            if ($businessIds->isEmpty() || $branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('items', fn ($q) => $q
                    ->whereIn('purchase_items.business_id', $businessIds)
                    ->whereIn('purchase_items.branch_id', $branchIds)
                );
            }
        }

        return $query;
    }

    protected function getLeaderboardStats(): array
    {
        [$user, $merchantId] = $this->authContext();

        if (! $merchantId) {
            return [
                'customers' => [],
                'vendors' => [],
                'variants' => [],
            ];
        }

        $salesQuery = $this->salesBaseQuery($user, $merchantId);
        $purchaseQuery = $this->purchaseBaseQuery($user, $merchantId);

        $saleIds = (clone $salesQuery)->pluck('sales.id');
        $purchaseIds = (clone $purchaseQuery)->pluck('purchases.id');

        $topCustomers = $saleIds->isEmpty()
            ? collect()
            : DB::table('sales')
                ->join('customers', 'customers.id', '=', 'sales.customer_id')
                ->whereIn('sales.id', $saleIds)
                ->selectRaw('customers.id as customer_id, customers.name as customer_name')
                ->selectRaw('COUNT(sales.id) as total_sales')
                ->selectRaw('COALESCE(SUM(sales.total_amount), 0) as total_amount')
                ->groupBy('customers.id', 'customers.name')
                ->orderByDesc('total_amount')
                ->limit(3)
                ->get()
                ->map(fn ($row) => [
                    'id' => $row->customer_id,
                    'name' => $row->customer_name ?? 'N/A',
                    'count' => (int) $row->total_sales,
                    'amount' => (float) $row->total_amount,
                ]);

        $topVendors = $purchaseIds->isEmpty()
            ? collect()
            : DB::table('purchases')
                ->join('vendors', 'vendors.id', '=', 'purchases.vendor_id')
                ->whereIn('purchases.id', $purchaseIds)
                ->selectRaw('vendors.id as vendor_id, vendors.name as vendor_name')
                ->selectRaw('COUNT(purchases.id) as total_purchases')
                ->selectRaw('COALESCE(SUM(purchases.total_amount), 0) as total_amount')
                ->groupBy('vendors.id', 'vendors.name')
                ->orderByDesc('total_amount')
                ->limit(3)
                ->get()
                ->map(fn ($row) => [
                    'id' => $row->vendor_id,
                    'name' => $row->vendor_name ?? 'N/A',
                    'count' => (int) $row->total_purchases,
                    'amount' => (float) $row->total_amount,
                ]);

        $soldVariants = $saleIds->isEmpty()
            ? collect()
            : DB::table('sale_item_variants as siv')
                ->join('sale_items as si', 'si.id', '=', 'siv.sale_item_id')
                ->join('product_variants as pv', 'pv.id', '=', 'siv.product_variant_id')
                ->leftJoin('products as p', 'p.id', '=', 'pv.product_id')
                ->whereIn('si.sale_id', $saleIds)
                ->selectRaw('pv.id as variant_id')
                ->selectRaw('COALESCE(NULLIF(pv.name, \'\'), pv.sku, \'Variant\') as variant_name')
                ->selectRaw('COALESCE(p.name, \'Product\') as product_name')
                ->selectRaw('COALESCE(pv.sku, \'-\') as sku')
                ->selectRaw('COALESCE(SUM(siv.quantity), 0) as sold_qty')
                ->selectRaw('COALESCE(SUM(siv.line_total), 0) as sold_amount')
                ->groupBy('pv.id', 'pv.name', 'pv.sku', 'p.name')
                ->get();

        $topVariants = $soldVariants
            ->map(function ($row) {
                return [
                    'id' => $row->variant_id,
                    'name' => $row->variant_name,
                    'product' => $row->product_name,
                    'sku' => $row->sku,
                    'qty' => (float) $row->sold_qty,
                    'amount' => (float) $row->sold_amount,
                ];
            })
            ->sortByDesc('qty')
            ->take(3)
            ->values();

        return [
            'customers' => $topCustomers->values()->all(),
            'vendors' => $topVendors->values()->all(),
            'variants' => $topVariants->all(),
        ];
    }

    protected function getSalesStats(): array
    {
        [$user, $merchantId] = $this->authContext();

        if (! $merchantId) {
            return $this->emptySalesStats();
        }

        $query = $this->salesBaseQuery($user, $merchantId);

        $saleIds = (clone $query)->pluck('sales.id');

        if ($saleIds->isEmpty()) {
            return $this->emptySalesStats();
        }

        $totalSales = $saleIds->count();
        $totalItemLines = DB::table('sale_items')
            ->whereIn('sale_id', $saleIds)
            ->count();

        $totalQuantitySold = DB::table('sale_item_variants as sv')
            ->join('sale_items as si', 'si.id', '=', 'sv.sale_item_id')
            ->whereIn('si.sale_id', $saleIds)
            ->sum('sv.quantity');

        $totalAmount = (clone $query)->sum('total_amount');
        $totalDiscount = DB::table('sale_items')
            ->whereIn('sale_id', $saleIds)
            ->sum(DB::raw('line_total * (discount / 100.0)'));

        $totalTax = DB::table('sale_items')
            ->whereIn('sale_id', $saleIds)
            ->sum(DB::raw('(line_total - (line_total * (discount / 100.0))) * (tax / 100.0)'));
        $totalSubtotal = (clone $query)->sum('subtotal');

        $netAmount = $totalAmount;
        $netDiscount = $totalDiscount;
        $netTax = $totalTax;
        $netSubtotal = $totalSubtotal;
        $netQuantity = $totalQuantitySold;

        $avgSale = $totalSales > 0 ? $netAmount / $totalSales : 0;

        return [
            'total_sales' => (int) $totalSales,
            'total_items_count' => (int) $totalItemLines,
            'total_quantity' => (float) $netQuantity,
            'total_amount' => (float) $netAmount,
            'total_discount' => (float) $netDiscount,
            'total_tax' => (float) $netTax,
            'total_subtotal' => (float) $netSubtotal,
            'avg_sale' => round($avgSale, 2),
        ];
    }

    protected function emptySalesStats(): array
    {
        return [
            'total_sales' => 0,
            'total_items_count' => 0,
            'total_quantity' => 0,
            'total_amount' => 0,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_subtotal' => 0,
            'avg_sale' => 0,
        ];
    }

    protected function getPurchaseStats(): array
    {
        [$user, $merchantId] = $this->authContext();

        if (! $merchantId) {
            return $this->emptyPurchaseStats();
        }

        $query = $this->purchaseBaseQuery($user, $merchantId);

        $purchaseIds = (clone $query)->pluck('purchases.id');

        if ($purchaseIds->isEmpty()) {
            return $this->emptyPurchaseStats();
        }

        $totalPurchases = $purchaseIds->count();

        $totalItemLines = DB::table('purchase_items')
            ->whereIn('purchase_id', $purchaseIds)
            ->count();

        $totalItemQuantity = DB::table('purchase_item_variants as piv')
            ->join('purchase_items as pi', 'pi.id', '=', 'piv.purchase_item_id')
            ->whereIn('pi.purchase_id', $purchaseIds)
            ->sum('piv.quantity');

        $totalAmount = (clone $query)->sum('total_amount');
        $totalDiscount = DB::table('purchase_items')
            ->whereIn('purchase_id', $purchaseIds)
            ->sum(DB::raw('line_total * (discount / 100.0)'));

        $totalTax = DB::table('purchase_items')
            ->whereIn('purchase_id', $purchaseIds)
            ->sum(DB::raw('(line_total - (line_total * (discount / 100.0))) * (tax / 100.0)'));
        $totalSubtotal = (clone $query)->sum('subtotal');

        $netAmount = $totalAmount;
        $netDiscount = $totalDiscount;
        $netTax = $totalTax;
        $netSubtotal = $totalSubtotal;
        $netQuantity = $totalItemQuantity;

        $avgPurchase = $totalPurchases > 0 ? $netAmount / $totalPurchases : 0;

        return [
            'total_purchases' => (int) $totalPurchases,
            'total_items_count' => (int) $totalItemLines,
            'total_items_quantity' => (float) $netQuantity,
            'total_amount' => (float) $netAmount,
            'total_discount' => (float) $netDiscount,
            'total_tax' => (float) $netTax,
            'total_subtotal' => (float) $netSubtotal,
            'avg_purchase' => round($avgPurchase, 2),
        ];
    }

    protected function emptyPurchaseStats(): array
    {
        return [
            'total_purchases' => 0,
            'total_items_count' => 0,
            'total_items_quantity' => 0,
            'total_amount' => 0,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_subtotal' => 0,
            'avg_purchase' => 0,
        ];
    }

    protected function getStockStats(): array
    {
        [$user, $merchantId] = $this->authContext();
        $filters = $this->filters();
        $selectedVariantIds = collect($filters['product_variant_ids'] ?? []);

        $variantIds = collect();
        $staffBusinessIds = collect();
        $staffBranchIds = collect();

        if ($user instanceof User) {
            $assignments = $this->staffAssignments($user);
            $branchIds = $assignments['branch_ids'];
            $businessIds = $assignments['business_ids'];
            $staffBranchIds = $branchIds;
            $staffBusinessIds = $businessIds;

            if ($branchIds->isEmpty() || $businessIds->isEmpty()) {
                return [
                    'total_products' => 0,
                    'total_purchased_qty' => 0,
                    'total_sold_qty' => 0,
                    'available_stock' => 0,
                    'total_amount' => 0,
                    'total_revenue' => 0,
                    'avg_selling_price' => 0,
                    'avg_buying_price' => 0,
                ];
            }

            $soldVariantIds = DB::table('sale_item_variants as sv')
                ->join('sale_items as si', 'si.id', '=', 'sv.sale_item_id')
                ->whereIn('si.business_id', $businessIds)
                ->whereIn('si.branch_id', $branchIds)
                ->pluck('sv.product_variant_id');

            $purchasedVariantIds = DB::table('purchase_item_variants as pv')
                ->join('purchase_items as pi', 'pi.id', '=', 'pv.purchase_item_id')
                ->whereIn('pi.business_id', $businessIds)
                ->whereIn('pi.branch_id', $branchIds)
                ->pluck('pv.product_variant_id');

            $variantIds = $soldVariantIds
                ->merge($purchasedVariantIds)
                ->unique()
                ->values();
        } else {
            if ($merchantId) {
                $variantIds = DB::table('product_variants')
                    ->where('merchant_id', $merchantId)
                    ->where('is_active', true)
                    ->pluck('id');
            }
        }

        if ($selectedVariantIds->isNotEmpty()) {
            $variantIds = $variantIds->intersect($selectedVariantIds)->values();
        }

        if ($variantIds->isEmpty()) {
            return [
                'total_products' => 0,
                'total_purchased_qty' => 0,
                'total_sold_qty' => 0,
                'available_stock' => 0,
                'total_amount' => 0,
                'total_revenue' => 0,
                'avg_selling_price' => 0,
                'avg_buying_price' => 0,
            ];
        }

        $totalProducts = $variantIds->count();

        $saleIds = $this->salesBaseQuery($user, $merchantId)->pluck('sales.id');
        $purchaseIds = $this->purchaseBaseQuery($user, $merchantId)->pluck('purchases.id');

        $totalPurchasedQty = $purchaseIds->isEmpty()
            ? 0
            : DB::table('purchase_item_variants as piv')
                ->join('purchase_items as pi', 'pi.id', '=', 'piv.purchase_item_id')
                ->whereIn('pi.purchase_id', $purchaseIds)
                ->whereIn('piv.product_variant_id', $variantIds)
                ->sum('piv.quantity');

        $netPurchasedQty = $totalPurchasedQty;

        $totalSoldQty = $saleIds->isEmpty()
            ? 0
            : DB::table('sale_item_variants as siv')
                ->join('sale_items as si', 'si.id', '=', 'siv.sale_item_id')
                ->whereIn('si.sale_id', $saleIds)
                ->whereIn('siv.product_variant_id', $variantIds)
                ->sum('siv.quantity');

        $netSoldQty = $totalSoldQty;

        $availableStock = $netPurchasedQty - $netSoldQty;

        $totalRevenue = $saleIds->isEmpty()
            ? 0
            : DB::table('sale_item_variants as siv')
                ->join('sale_items as si', 'si.id', '=', 'siv.sale_item_id')
                ->join('product_variants as pv', 'pv.id', '=', 'siv.product_variant_id')
                ->whereIn('si.sale_id', $saleIds)
                ->whereIn('pv.id', $variantIds)
                ->sum(DB::raw('siv.quantity * pv.selling_price'));

        $netRevenue = $totalRevenue;
        $stockValueQuery = DB::table('product_variants')
            ->whereIn('id', $variantIds)
            ->select('id')
            ->selectRaw(
                $this->stockValueExpression(
                    $merchantId,
                    $filters,
                    $staffBusinessIds->all(),
                    $staffBranchIds->all(),
                ).' as total_amount'
            );

        $totalAmount = (float) DB::query()
            ->fromSub($stockValueQuery, 'stock')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total_amount')
            ->value('total_amount');

        $totalBuyingCost = $purchaseIds->isEmpty()
            ? 0
            : DB::table('purchase_item_variants as piv')
                ->join('purchase_items as pi', 'pi.id', '=', 'piv.purchase_item_id')
                ->join('product_variants as pv', 'pv.id', '=', 'piv.product_variant_id')
                ->whereIn('pi.purchase_id', $purchaseIds)
                ->whereIn('pv.id', $variantIds)
                ->sum(DB::raw('piv.quantity * pv.purchase_price'));

        $netBuyingCost = $totalBuyingCost;

        return [
            'total_products' => (int) $totalProducts,
            'total_purchased_qty' => (float) $netPurchasedQty,
            'total_sold_qty' => (float) $netSoldQty,
            'available_stock' => (float) $availableStock,
            'total_amount' => (float) $totalAmount,
            'total_revenue' => (float) $netRevenue,
            'avg_selling_price' => $netSoldQty > 0 ? round($netRevenue / $netSoldQty, 2) : 0,
            'avg_buying_price' => $netPurchasedQty > 0 ? round($netBuyingCost / $netPurchasedQty, 2) : 0,
        ];
    }

    protected function getReturnStats(): array
    {
        [$user, $merchantId] = $this->authContext();

        if (! $merchantId) {
            return [
                'sales' => [
                    'total_returns' => 0,
                    'total_amount' => 0,
                    'total_quantity' => 0,
                ],
                'purchases' => [
                    'total_returns' => 0,
                    'total_amount' => 0,
                    'total_quantity' => 0,
                ],
            ];
        }

        $salesQuery = $this->salesBaseQuery($user, $merchantId);
        $purchaseQuery = $this->purchaseBaseQuery($user, $merchantId);

        $saleIds = (clone $salesQuery)->pluck('sales.id');
        $purchaseIds = (clone $purchaseQuery)->pluck('purchases.id');

        $saleReturnIds = $saleIds->isEmpty()
            ? collect()
            : DB::table('sale_returns')
                ->whereIn('sale_id', $saleIds)
                ->whereNull('deleted_at')
                ->pluck('id');

        $purchaseReturnIds = $purchaseIds->isEmpty()
            ? collect()
            : DB::table('purchase_returns')
                ->whereIn('purchase_id', $purchaseIds)
                ->whereNull('deleted_at')
                ->pluck('id');

        $saleReturnsTotalAmount = $saleReturnIds->isEmpty()
            ? 0
            : (float) DB::table('sale_returns')
                ->whereIn('id', $saleReturnIds)
                ->sum('total_amount');

        $saleReturnsQuantity = $saleReturnIds->isEmpty()
            ? 0
            : (float) DB::table('sale_return_item_variants as srv')
                ->join('sale_return_items as sri', 'sri.id', '=', 'srv.sale_return_item_id')
                ->whereIn('sri.sale_return_id', $saleReturnIds)
                ->whereNull('sri.deleted_at')
                ->whereNull('srv.deleted_at')
                ->sum('srv.quantity');

        $purchaseReturnsTotalAmount = $purchaseReturnIds->isEmpty()
            ? 0
            : (float) DB::table('purchase_returns')
                ->whereIn('id', $purchaseReturnIds)
                ->sum('total_amount');

        $purchaseReturnsQuantity = $purchaseReturnIds->isEmpty()
            ? 0
            : (float) DB::table('purchase_return_item_variants as prv')
                ->join('purchase_return_items as pri', 'pri.id', '=', 'prv.purchase_return_item_id')
                ->whereIn('pri.purchase_return_id', $purchaseReturnIds)
                ->whereNull('pri.deleted_at')
                ->whereNull('prv.deleted_at')
                ->sum('prv.quantity');

        return [
            'sales' => [
                'total_returns' => (int) $saleReturnIds->count(),
                'total_amount' => $saleReturnsTotalAmount,
                'total_quantity' => $saleReturnsQuantity,
            ],
            'purchases' => [
                'total_returns' => (int) $purchaseReturnIds->count(),
                'total_amount' => $purchaseReturnsTotalAmount,
                'total_quantity' => $purchaseReturnsQuantity,
            ],
        ];
    }

    protected function getProfitLossStats(): array
    {
        [$user, $merchantId] = $this->authContext();

        if (! $merchantId) {
            return [
                'gross_profit' => 0,
                'net_sales' => 0,
                'net_purchases' => 0,
                'sales_total' => 0,
                'sales_returns' => 0,
                'purchases_total' => 0,
                'purchase_returns' => 0,
                'expenses' => 0,
                'payrolls' => 0,
                'net_profit' => 0,
            ];
        }

        $salesQuery = $this->salesBaseQuery($user, $merchantId);
        $purchaseQuery = $this->purchaseBaseQuery($user, $merchantId);

        $saleIds = (clone $salesQuery)->pluck('sales.id');
        $purchaseIds = (clone $purchaseQuery)->pluck('purchases.id');

        $salesTotal = $saleIds->isEmpty()
            ? 0
            : (float) (clone $salesQuery)->sum('total_amount');

        $purchasesTotal = $purchaseIds->isEmpty()
            ? 0
            : (float) (clone $purchaseQuery)->sum('total_amount');

        $salesReturns = $saleIds->isEmpty()
            ? 0
            : (float) DB::table('sale_returns')
                ->whereIn('sale_id', $saleIds)
                ->whereNull('deleted_at')
                ->sum('total_amount');

        $purchaseReturns = $purchaseIds->isEmpty()
            ? 0
            : (float) DB::table('purchase_returns')
                ->whereIn('purchase_id', $purchaseIds)
                ->whereNull('deleted_at')
                ->sum('total_amount');

        $netSales = $salesTotal;
        $netPurchases = $purchasesTotal;
        $grossProfit = $netSales - $netPurchases;
        $filters = $this->filters();

        $expenses = (float) (clone $this->expenseBaseQuery($user, $merchantId))->sum('total_amount');

        $payrolls = (float) Payroll::query()
            ->where('merchant_id', $merchantId)
            ->where('status', Payroll::STATUS_PAID)
            ->when(
                $filters['date_from'],
                fn (EloquentBuilder $query, $date) => $query->whereDate('payment_date', '>=', $date),
            )
            ->when(
                $filters['date_to'],
                fn (EloquentBuilder $query, $date) => $query->whereDate('payment_date', '<=', $date),
            )
            ->sum('net_salary');

        $netProfit = $grossProfit - $expenses - $payrolls;

        return [
            'gross_profit' => $grossProfit,
            'net_sales' => $netSales,
            'net_purchases' => $netPurchases,
            'sales_total' => $salesTotal,
            'sales_returns' => $salesReturns,
            'purchases_total' => $purchasesTotal,
            'purchase_returns' => $purchaseReturns,
            'expenses' => $expenses,
            'payrolls' => $payrolls,
            'net_profit' => $netProfit,
        ];
    }

    protected function getTrendData(): array
    {
        [$user, $merchantId] = $this->authContext();

        $months = collect(range(5, 0))
            ->map(fn ($offset) => Carbon::now()->startOfMonth()->subMonths($offset));

        $labels = $months->map(fn (Carbon $date) => $date->format('M'))->values();
        $salesSeries = $months->map(fn () => 0)->values();
        $purchaseSeries = $months->map(fn () => 0)->values();

        if (! $merchantId) {
            return [
                'labels' => $labels->all(),
                'sales' => $salesSeries->all(),
                'purchases' => $purchaseSeries->all(),
            ];
        }

        $salesQuery = $this->salesBaseQuery($user, $merchantId);
        $purchaseQuery = $this->purchaseBaseQuery($user, $merchantId);

        $saleIds = (clone $salesQuery)->pluck('sales.id');
        $purchaseIds = (clone $purchaseQuery)->pluck('purchases.id');

        foreach ($months as $index => $month) {
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $salesSeries[$index] = $saleIds->isEmpty()
                ? 0
                : (int) DB::table('sales')
                    ->whereIn('id', $saleIds)
                    ->whereBetween('created_at', [$start, $end])
                    ->count();

            $purchaseSeries[$index] = $purchaseIds->isEmpty()
                ? 0
                : (int) DB::table('purchases')
                    ->whereIn('id', $purchaseIds)
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
        }

        return [
            'labels' => $labels->all(),
            'sales' => $salesSeries->all(),
            'purchases' => $purchaseSeries->all(),
        ];
    }

    protected function getCreditStats(): array
    {
        [$user, $merchantId] = $this->authContext();

        if (! $merchantId) {
            return [
                'receivable_total' => 0,
                'payable_total' => 0,
                'top_customers' => [],
                'top_vendors' => [],
            ];
        }

        $creditSalesQuery = $this->salesBaseQuery($user, $merchantId)
            ->where('due_amount', '>', 0);

        $creditPurchasesQuery = $this->purchaseBaseQuery($user, $merchantId)
            ->where('due_amount', '>', 0);

        $creditSaleIds = (clone $creditSalesQuery)->pluck('sales.id');
        $creditPurchaseIds = (clone $creditPurchasesQuery)->pluck('purchases.id');

        $creditSalesTotal = (float) (clone $creditSalesQuery)->sum('due_amount');
        $creditPurchasesTotal = (float) (clone $creditPurchasesQuery)->sum('due_amount');

        $customerCredits = $creditSaleIds->isEmpty()
            ? collect()
            : DB::table('sales')
                ->join('customers', 'customers.id', '=', 'sales.customer_id')
                ->whereIn('sales.id', $creditSaleIds)
                ->selectRaw('customers.id as customer_id, customers.name as customer_name')
                ->selectRaw('COUNT(DISTINCT sales.id) as credit_sales')
                ->selectRaw('COALESCE(SUM(sales.due_amount), 0) as credit_amount')
                ->groupBy('customers.id', 'customers.name')
                ->get();

        $topCustomers = $customerCredits
            ->map(function ($row) {
                return [
                    'id' => $row->customer_id,
                    'name' => $row->customer_name ?? 'N/A',
                    'count' => (int) $row->credit_sales,
                    'amount' => max(0, (float) $row->credit_amount),
                ];
            })
            ->sortByDesc('amount')
            ->take(2)
            ->values()
            ->all();

        $vendorCredits = $creditPurchaseIds->isEmpty()
            ? collect()
            : DB::table('purchases')
                ->join('vendors', 'vendors.id', '=', 'purchases.vendor_id')
                ->whereIn('purchases.id', $creditPurchaseIds)
                ->selectRaw('vendors.id as vendor_id, vendors.name as vendor_name')
                ->selectRaw('COUNT(DISTINCT purchases.id) as credit_purchases')
                ->selectRaw('COALESCE(SUM(purchases.due_amount), 0) as credit_amount')
                ->groupBy('vendors.id', 'vendors.name')
                ->get();

        $topVendors = $vendorCredits
            ->map(function ($row) {
                return [
                    'id' => $row->vendor_id,
                    'name' => $row->vendor_name ?? 'N/A',
                    'count' => (int) $row->credit_purchases,
                    'amount' => max(0, (float) $row->credit_amount),
                ];
            })
            ->sortByDesc('amount')
            ->take(2)
            ->values()
            ->all();

        return [
            'receivable_total' => max(0, $creditSalesTotal),
            'payable_total' => max(0, $creditPurchasesTotal),
            'top_customers' => $topCustomers,
            'top_vendors' => $topVendors,
        ];
    }

    protected function expenseBaseQuery($user, string $merchantId): EloquentBuilder
    {
        $filters = $this->filters();

        $query = Expense::query()
            ->where('merchant_id', $merchantId)
            ->when(
                $filters['business_id'],
                fn (EloquentBuilder $query, $businessId) => $query->where('business_id', $businessId),
            )
            ->when(
                $filters['branch_id'],
                fn (EloquentBuilder $query, $branchId) => $query->where('branch_id', $branchId),
            )
            ->when(
                $filters['date_from'],
                fn (EloquentBuilder $query, $date) => $query->whereDate('expense_date', '>=', $date),
            )
            ->when(
                $filters['date_to'],
                fn (EloquentBuilder $query, $date) => $query->whereDate('expense_date', '<=', $date),
            );

        if ($user instanceof User) {
            $assignments = $this->staffAssignments($user);
            $businessIds = $assignments['business_ids'];
            $branchIds = $assignments['branch_ids'];

            if ($businessIds->isEmpty() || $branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query
                    ->whereIn('business_id', $businessIds)
                    ->whereIn('branch_id', $branchIds);
            }
        }

        return $query;
    }

    protected function getExpenseStats(): array
    {
        [$user, $merchantId] = $this->authContext();

        if (! $merchantId) {
            return [
                'total_expenses' => 0,
                'total_amount' => 0,
                'avg_expense' => 0,
            ];
        }

        $query = $this->expenseBaseQuery($user, $merchantId);
        $count = (clone $query)->count();
        $amount = (float) (clone $query)->sum('total_amount');

        return [
            'total_expenses' => (int) $count,
            'total_amount' => $amount,
            'avg_expense' => $count > 0 ? round($amount / $count, 2) : 0,
        ];
    }

    protected function getFundStats(): array
    {
        [$user, $merchantId] = $this->authContext();

        if (! $merchantId) {
            return [
                'opening_total_funds' => 0,
                'sales_cash_inflow' => 0,
                'purchases_cash_outflow' => 0,
                'expenses_outflow' => 0,
                'payroll_outflow' => 0,
                'cash_flow_net' => 0,
                'cash_flow_received' => 0,
                'cash_flow_paid' => 0,
                'cash_flow_receivable' => 0,
                'cash_flow_payable' => 0,
                'net_cash_movement' => 0,
                'current_total_funds' => 0,
                'cash_ledger' => 0,
                'ubl_ledger' => 0,
                'bank_ledgers' => [],
            ];
        }

        $merchant = Merchant::query()->find($merchantId);

        $openingTotalFunds = (float) ($merchant?->cash_in_hand ?? 0) + (float) ($merchant?->cash_in_bank ?? 0);

        $salesQuery = $this->salesBaseQuery($user, $merchantId);
        $cashSalesAmount = (float) (clone $salesQuery)->sum('paid_amount');

        $purchasesQuery = $this->purchaseBaseQuery($user, $merchantId);
        $cashPurchasesAmount = (float) (clone $purchasesQuery)->sum('paid_amount');
        $expenseAmount = (float) (clone $this->expenseBaseQuery($user, $merchantId))->sum('total_amount');
        $filters = $this->filters();
        $payrollAmount = (float) Payroll::query()
            ->where('merchant_id', $merchantId)
            ->where('status', Payroll::STATUS_PAID)
            ->when(
                $filters['date_from'],
                fn (EloquentBuilder $query, $date) => $query->whereDate('payment_date', '>=', $date),
            )
            ->when(
                $filters['date_to'],
                fn (EloquentBuilder $query, $date) => $query->whereDate('payment_date', '<=', $date),
            )
            ->sum('net_salary');

        $cashFlowQuery = CashFlow::query()
            ->withoutTrashed()
            ->activeLedger()
            ->where('merchant_id', $merchantId)
            ->when(
                $filters['date_from'],
                fn (EloquentBuilder $query, $date) => $query->whereDate('flow_date', '>=', $date),
            )
            ->when(
                $filters['date_to'],
                fn (EloquentBuilder $query, $date) => $query->whereDate('flow_date', '<=', $date),
            );

        $cashFlowIn = (float) (clone $cashFlowQuery)
            ->where('direction', 'in')
            ->sum('amount');

        $cashFlowOut = (float) (clone $cashFlowQuery)
            ->where('direction', 'out')
            ->sum('amount');

        $cashFlowNet = $cashFlowIn - $cashFlowOut;

        $cashFlowBalances = (clone $cashFlowQuery)
            ->select('flow_type')
            ->selectRaw('
                COALESCE(SUM(
                    CASE
                        WHEN settlement_for_id IS NULL THEN amount
                        ELSE -amount
                    END
                ), 0) as balance
            ')
            ->groupBy('flow_type')
            ->pluck('balance', 'flow_type');

        $cashFlowReceivable = max(0, (float) ($cashFlowBalances['loan'] ?? 0));
        $cashFlowPayable = max(0, (float) ($cashFlowBalances['advance'] ?? 0));

        $salesCashInflow = $cashSalesAmount;
        $purchasesCashOutflow = $cashPurchasesAmount;
        $expensesOutflow = $expenseAmount;
        $payrollOutflow = $payrollAmount;
        $netCashMovement = $salesCashInflow - $purchasesCashOutflow - $expensesOutflow - $payrollOutflow + $cashFlowNet;

        return [
            'opening_total_funds' => $openingTotalFunds,
            'sales_cash_inflow' => $salesCashInflow,
            'purchases_cash_outflow' => $purchasesCashOutflow,
            'expenses_outflow' => $expensesOutflow,
            'payroll_outflow' => $payrollOutflow,
            'cash_flow_net' => $cashFlowNet,
            'cash_flow_received' => $cashFlowIn,
            'cash_flow_paid' => $cashFlowOut,
            'cash_flow_receivable' => $cashFlowReceivable,
            'cash_flow_payable' => $cashFlowPayable,
            'net_cash_movement' => $netCashMovement,
            'current_total_funds' => $openingTotalFunds + $netCashMovement,
            'cash_ledger' => (float) (LedgerAccount::query()
                ->where('merchant_id', $merchantId)
                ->where('code', FinanceLedger::CASH_ACCOUNT_CODE)
                ->first()
                ?->postedBalance() ?? 0),
            'ubl_ledger' => (float) (LedgerAccount::query()
                ->where('merchant_id', $merchantId)
                ->where('code', FinanceLedger::BANK_ACCOUNT_CODE)
                ->first()
                ?->postedBalance() ?? 0),
            'bank_ledgers' => LedgerAccount::query()
                ->where('merchant_id', $merchantId)
                ->where('is_bank', true)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (LedgerAccount $account): array => [
                    'name' => $account->name,
                    'balance' => (float) $account->postedBalance(),
                ])
                ->all(),
        ];
    }
}
