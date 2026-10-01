<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductStockAvailability
{
    public static function productTracksInventory(Product $product): bool
    {
        return (bool) $product->track_inventory && $product->type !== 'service';
    }

    public static function defaultVariantIdForProduct(?string $productId): ?string
    {
        if (! filled($productId)) {
            return null;
        }

        return ProductVariant::query()
            ->withoutTrashed()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('id');
    }

    public static function resolveVariantIdForProduct(?string $productId, mixed $variantId = null): ?string
    {
        if (filled($variantId)) {
            return (string) $variantId;
        }

        return self::ensureDefaultVariantIdForProduct($productId);
    }

    public static function ensureDefaultVariantIdForProduct(?string $productId): ?string
    {
        if (! filled($productId)) {
            return null;
        }

        $existing = self::defaultVariantIdForProduct($productId);

        if (filled($existing)) {
            return $existing;
        }

        $product = Product::query()->withoutTrashed()->find($productId);

        if (! $product) {
            return null;
        }

        $skuBase = filled($product->sku) ? (string) $product->sku : 'SKU-'.substr((string) $product->id, 0, 8);

        $variant = ProductVariant::query()->create([
            'merchant_id' => $product->merchant_id,
            'product_id' => $product->id,
            'name' => 'Standard',
            'sku' => $skuBase.'-STD',
            'selling_price' => $product->selling_price,
            'purchase_price' => $product->purchase_price,
            'is_active' => true,
        ]);

        return (string) $variant->id;
    }

    public static function resolveBranchIdForProduct(?string $productId, mixed $branchId = null): ?string
    {
        if (filled($branchId)) {
            $branch = Branch::query()->withTrashed()->find($branchId);

            if ($branch) {
                return (string) $branch->id;
            }
        }

        if (! filled($productId)) {
            return null;
        }

        return DB::table('branch_products')
            ->where('product_id', $productId)
            ->orderBy('created_at')
            ->value('branch_id');
    }

    public static function variantStock(string $variantId, ?string $branchId = null, ?string $excludeSaleId = null): float
    {
        $purchased = self::purchasedQuantity($variantId, $branchId);
        $sold = self::soldQuantity($variantId, $branchId, $excludeSaleId);

        return max(0, $purchased - $sold);
    }

    public static function purchasedQuantity(string $variantId, ?string $branchId = null): float
    {
        $query = DB::table('purchase_item_variants as piv')
            ->join('purchase_items as pi', 'pi.id', '=', 'piv.purchase_item_id')
            ->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->where('piv.product_variant_id', $variantId)
            ->whereNull('p.deleted_at');

        if (filled($branchId)) {
            $query->where('pi.branch_id', $branchId);
        }

        return (float) $query->sum('piv.quantity');
    }

    /**
     * Sold quantity committed at sale time (cash or credit — not when payment is collected).
     */
    public static function soldQuantity(string $variantId, ?string $branchId = null, ?string $excludeSaleId = null): float
    {
        $query = DB::table('sale_item_variants as siv')
            ->join('sale_items as si', 'si.id', '=', 'siv.sale_item_id')
            ->join('sales as s', 's.id', 'si.sale_id')
            ->where('siv.product_variant_id', $variantId)
            ->where('s.status', Sale::STATUS_POSTED)
            ->whereNull('s.deleted_at');

        if (filled($branchId)) {
            $query->where('si.branch_id', $branchId);
        }

        if (filled($excludeSaleId)) {
            $query->where('s.id', '!=', $excludeSaleId);
        }

        return (float) $query->sum('siv.quantity');
    }

    public static function isVariantAvailable(
        ProductVariant $variant,
        ?string $branchId = null,
        int $requestedQty = 1,
        ?string $excludeSaleId = null,
    ): bool {
        $variant->loadMissing('product');

        $product = $variant->product;

        if (! $product || ! self::productTracksInventory($product)) {
            return true;
        }

        return self::variantStock($variant->id, $branchId, $excludeSaleId) >= $requestedQty;
    }

    public static function isProductAvailable(Product $product, ?string $branchId = null, ?string $excludeSaleId = null): bool
    {
        if (! self::productTracksInventory($product)) {
            return true;
        }

        $variantIds = $product->variants()
            ->withoutTrashed()
            ->where('is_active', true)
            ->pluck('id');

        if ($variantIds->isEmpty()) {
            return self::productLevelStock((string) $product->id, $branchId, $excludeSaleId) > 0;
        }

        return $variantIds->contains(
            fn (string $variantId): bool => self::variantStock($variantId, $branchId, $excludeSaleId) > 0
        );
    }

    public static function productLevelStock(string $productId, ?string $branchId = null, ?string $excludeSaleId = null): float
    {
        $purchased = self::purchasedQuantityForProduct($productId, $branchId);
        $sold = self::soldQuantityForProduct($productId, $branchId, $excludeSaleId);

        return max(0, $purchased - $sold);
    }

    public static function purchasedQuantityForProduct(string $productId, ?string $branchId = null): float
    {
        $fromVariants = (float) DB::table('purchase_item_variants as piv')
            ->join('purchase_items as pi', 'pi.id', '=', 'piv.purchase_item_id')
            ->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->join('product_variants as pv', 'pv.id', '=', 'piv.product_variant_id')
            ->where('pv.product_id', $productId)
            ->whereNull('p.deleted_at')
            ->when(filled($branchId), fn ($query) => $query->where('pi.branch_id', $branchId))
            ->sum('piv.quantity');

        $withoutVariants = (float) DB::table('purchase_items as pi')
            ->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->where('pi.product_id', $productId)
            ->whereNull('p.deleted_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('purchase_item_variants as piv')
                ->whereColumn('piv.purchase_item_id', 'pi.id'))
            ->when(filled($branchId), fn ($query) => $query->where('pi.branch_id', $branchId))
            ->sum('pi.quantity');

        return $fromVariants + $withoutVariants;
    }

    public static function soldQuantityForProduct(string $productId, ?string $branchId = null, ?string $excludeSaleId = null): float
    {
        $fromVariants = (float) DB::table('sale_item_variants as siv')
            ->join('sale_items as si', 'si.id', '=', 'siv.sale_item_id')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('product_variants as pv', 'pv.id', '=', 'siv.product_variant_id')
            ->where('pv.product_id', $productId)
            ->where('s.status', Sale::STATUS_POSTED)
            ->whereNull('s.deleted_at')
            ->when(filled($branchId), fn ($query) => $query->where('si.branch_id', $branchId))
            ->when(filled($excludeSaleId), fn ($query) => $query->where('s.id', '!=', $excludeSaleId))
            ->sum('siv.quantity');

        $withoutVariants = (float) DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('si.product_id', $productId)
            ->where('s.status', Sale::STATUS_POSTED)
            ->whereNull('s.deleted_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('sale_item_variants as siv')
                ->whereColumn('siv.sale_item_id', 'si.id'))
            ->when(filled($branchId), fn ($query) => $query->where('si.branch_id', $branchId))
            ->when(filled($excludeSaleId), fn ($query) => $query->where('s.id', '!=', $excludeSaleId))
            ->sum('si.quantity');

        return $fromVariants + $withoutVariants;
    }

    public static function isProductIdAvailable(string $productId, ?string $branchId = null, ?string $excludeSaleId = null): bool
    {
        $product = Product::query()
            ->select(['id', 'track_inventory', 'type'])
            ->find($productId);

        return $product ? self::isProductAvailable($product, $branchId, $excludeSaleId) : false;
    }

    public static function isVariantIdAvailable(
        string $variantId,
        ?string $branchId = null,
        int $requestedQty = 1,
        ?string $excludeSaleId = null,
    ): bool {
        $variant = ProductVariant::query()->with('product')->find($variantId);

        return $variant
            ? self::isVariantAvailable($variant, $branchId, $requestedQty, $excludeSaleId)
            : false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public static function validateSaleItemsStock(array $items, ?string $excludeSaleId = null): ?string
    {
        $requiredByVariantBranch = [];

        foreach ($items as $item) {
            $productId = $item['product_id'] ?? null;
            $variantId = $item['product_variant_id'] ?? null;
            $branchId = filled($item['branch_id'] ?? null) ? (string) $item['branch_id'] : null;
            $qty = max(1, (int) ceil((float) ($item['quantity'] ?? 0)));

            if (! filled($productId) || $qty <= 0) {
                continue;
            }

            $product = Product::query()
                ->select(['id', 'name', 'track_inventory', 'type'])
                ->find($productId);

            if (! $product || ! self::productTracksInventory($product)) {
                continue;
            }

            if (filled($variantId)) {
                $key = $variantId.'|'.($branchId ?? 'all');
                $requiredByVariantBranch[$key] = [
                    'variant_id' => (string) $variantId,
                    'branch_id' => $branchId,
                    'product_name' => $product->name,
                    'quantity' => ($requiredByVariantBranch[$key]['quantity'] ?? 0) + $qty,
                ];

                continue;
            }

            if (! self::isProductAvailable($product, $branchId, $excludeSaleId)) {
                return "{$product->name} is out of stock.";
            }

            $available = self::productLevelStock((string) $product->id, $branchId, $excludeSaleId);

            if ($qty > $available) {
                return "{$product->name} only has ".self::formatQuantity($available).' unit(s) in stock.';
            }
        }

        foreach ($requiredByVariantBranch as $requirement) {
            $variant = ProductVariant::query()->with('product')->find($requirement['variant_id']);

            if (! $variant) {
                continue;
            }

            if (! self::isVariantAvailable($variant, $requirement['branch_id'], (int) $requirement['quantity'], $excludeSaleId)) {
                $available = self::variantStock($requirement['variant_id'], $requirement['branch_id'], $excludeSaleId);

                return "{$requirement['product_name']} only has "
                    .self::formatQuantity($available)
                    .' unit(s) in stock.';
            }
        }

        return null;
    }

    /**
     * @return array{
     *     in_stock: bool,
     *     stock: float|null,
     *     tracks_inventory: bool
     * }
     */
    public static function productPosMeta(Product $product, ?string $branchId = null): array
    {
        $tracksInventory = self::productTracksInventory($product);

        if (! $tracksInventory) {
            return [
                'in_stock' => true,
                'stock' => null,
                'tracks_inventory' => false,
            ];
        }

        $stock = self::productTotalStock($product, $branchId);

        return [
            'in_stock' => self::isProductAvailable($product, $branchId),
            'stock' => $stock,
            'tracks_inventory' => true,
        ];
    }

    /**
     * @return array{
     *     in_stock: bool,
     *     stock: float|null,
     *     tracks_inventory: bool
     * }
     */
    public static function variantPosMeta(ProductVariant $variant, ?string $branchId = null): array
    {
        $variant->loadMissing('product');
        $product = $variant->product;

        if (! $product || ! self::productTracksInventory($product)) {
            return [
                'in_stock' => true,
                'stock' => null,
                'tracks_inventory' => false,
            ];
        }

        $stock = self::variantStock($variant->id, $branchId);

        return [
            'in_stock' => $stock > 0,
            'stock' => $stock,
            'tracks_inventory' => true,
        ];
    }

    public static function productTotalStock(Product $product, ?string $branchId = null, ?string $excludeSaleId = null): float
    {
        if (! self::productTracksInventory($product)) {
            return PHP_FLOAT_MAX;
        }

        $variantIds = $product->variants()
            ->withoutTrashed()
            ->where('is_active', true)
            ->pluck('id');

        if ($variantIds->isEmpty()) {
            return self::productLevelStock((string) $product->id, $branchId, $excludeSaleId);
        }

        return (float) $variantIds->sum(
            fn (string $variantId): float => self::variantStock($variantId, $branchId, $excludeSaleId)
        );
    }

    public static function productOptionLabel(Product $product, ?string $branchId = null): string
    {
        $label = "{$product->name} ({$product->sku})";

        if (! self::productTracksInventory($product)) {
            return $label;
        }

        $quantity = self::productTotalStock($product, $branchId);

        if ($quantity <= 0) {
            return "{$label} — Out of stock";
        }

        return $label.' — Qty: '.self::formatQuantity($quantity);
    }

    public static function variantOptionLabel(ProductVariant $variant, ?string $branchId = null): string
    {
        $label = (string) ($variant->name ?? $variant->sku ?? $variant->id);

        $variant->loadMissing('product');

        if ($variant->product && self::productTracksInventory($variant->product)) {
            $stock = self::variantStock($variant->id, $branchId);

            if ($stock <= 0) {
                return "{$label} — Out of stock";
            }

            return "{$label} (Stock: ".self::formatQuantity($stock).')';
        }

        return $label;
    }

    public static function formatQuantity(float $quantity): string
    {
        if (floor($quantity) === $quantity) {
            return (string) (int) $quantity;
        }

        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function posProductsForMerchant(
        string $merchantId,
        ?string $search = null,
        ?string $categoryId = null,
        bool $inStockOnly = false,
    ): array {
        $query = Product::query()
            ->withoutTrashed()
            ->where('is_active', true)
            ->where('merchant_id', $merchantId)
            ->with('productImage');

        if (filled($search)) {
            $term = '%'.mb_strtolower(trim($search)).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(sku) LIKE ?', [$term]));
        }

        if (filled($categoryId)) {
            $query->where('category_id', $categoryId);
        }

        return $query
            ->limit(50)
            ->get(['id', 'name', 'sku', 'selling_price', 'track_inventory', 'type'])
            ->filter(function (Product $product) use ($inStockOnly): bool {
                if (! $inStockOnly) {
                    return true;
                }

                return self::isProductAvailable($product);
            })
            ->map(fn (Product $product): array => self::formatPosProductPayload($product))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function posVariantsForProduct(
        string $productId,
        ?string $branchId = null,
        bool $inStockOnly = false,
    ): array {
        return ProductVariant::query()
            ->withoutTrashed()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->with('product:id,track_inventory,type')
            ->limit(50)
            ->get(['id', 'product_id', 'name', 'sku', 'selling_price'])
            ->map(function (ProductVariant $variant) use ($branchId): array {
                $stockMeta = self::variantPosMeta($variant, $branchId);

                return [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'selling_price' => $variant->selling_price,
                    'in_stock' => $stockMeta['in_stock'],
                    'stock' => $stockMeta['stock'],
                    'tracks_inventory' => $stockMeta['tracks_inventory'],
                ];
            })
            ->filter(function (array $variant) use ($inStockOnly): bool {
                if (! $inStockOnly) {
                    return true;
                }

                return (bool) $variant['in_stock'];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function formatPosProductPayload(Product $product): array
    {
        $product->loadMissing('productImage');
        $stockMeta = self::productPosMeta($product);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'selling_price' => $product->selling_price,
            'image' => $product->productImage
                ? 'https://hdojyhoqzioxnbkuxjno.supabase.co/storage/v1/object/public/product-images/products/'.basename($product->productImage->photo_url)
                : null,
            'in_stock' => $stockMeta['in_stock'],
            'stock' => $stockMeta['stock'],
            'tracks_inventory' => $stockMeta['tracks_inventory'],
        ];
    }

    /**
     * @param  list<string>  $alwaysIncludeProductIds
     * @return array<string, string>
     */
    public static function saleProductOptions(
        ?string $merchantId,
        ?string $search = null,
    ): array {
        if (! filled($merchantId)) {
            return [];
        }

        $query = Product::query()
            ->withoutTrashed()
            ->where('products.is_active', true)
            ->where('products.merchant_id', $merchantId);

        if (filled($search)) {
            $term = '%'.mb_strtolower(trim($search)).'%';

            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(products.name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(products.sku) LIKE ?', [$term]);
            });
        }

        $query->orderBy('products.name');

        if (filled($search)) {
            $query->limit(50);
        }

        return $query
            ->get(['products.id', 'products.name', 'products.sku', 'products.track_inventory', 'products.type'])
            ->mapWithKeys(fn (Product $product) => [
                $product->id => self::productOptionLabel($product),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function saleVariantOptions(
        string $productId,
        ?string $branchId = null,
    ): array {
        return ProductVariant::query()
            ->withoutTrashed()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (ProductVariant $variant) => [
                $variant->id => self::variantOptionLabel($variant, $branchId),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, string>  $variantIds
     * @return array<string, float>
     */
    public static function variantStockMap(Collection $variantIds, ?string $branchId = null): array
    {
        return $variantIds
            ->mapWithKeys(fn (string $variantId): array => [
                $variantId => self::variantStock($variantId, $branchId),
            ])
            ->all();
    }
}
