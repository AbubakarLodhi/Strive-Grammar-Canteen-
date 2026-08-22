<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseItemVariant;
use App\Models\Vendor;
use App\Support\GeoFormFields;
use App\Support\ProductStockAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class CanteenStockImporter
{
    public const OPENING_PURCHASE_NO = 'STOCK-SHEET';

    public const OPENING_VENDOR_NAME = 'Opening Stock Sheet';

    /**
     * @return array{
     *     products_created: int,
     *     products_updated: int,
     *     rows_imported: int,
     *     total_quantity: float,
     *     skipped: int,
     *     items: list<array{name: string, quantity: float, purchase_price: float, selling_price: float}>
     * }
     */
    public function importFromPath(string $path, Merchant $merchant, ?string $createdBy = null): array
    {
        $rows = $this->parseSpreadsheet($path);

        if ($rows === []) {
            throw new RuntimeException('No product rows found in the spreadsheet. Expected columns: Product Name, Qty, Pr Price, Sell Price.');
        }

        return $this->importRows($rows, $merchant, $createdBy);
    }

    /**
     * @param  list<array{name: string, quantity: float, purchase_price: float, selling_price: float}>  $rows
     * @return array{
     *     products_created: int,
     *     products_updated: int,
     *     rows_imported: int,
     *     total_quantity: float,
     *     skipped: int,
     *     items: list<array{name: string, quantity: float, purchase_price: float, selling_price: float}>
     * }
     */
    public function importRows(array $rows, Merchant $merchant, ?string $createdBy = null): array
    {
        $business = $this->ensureBusiness($merchant);
        $branch = $this->ensureBranch($merchant, $business);
        $vendor = $this->ensureVendor($merchant);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $totalQuantity = 0.0;

        DB::transaction(function () use (
            $rows,
            $merchant,
            $business,
            $branch,
            $vendor,
            $createdBy,
            &$created,
            &$updated,
            &$skipped,
            &$totalQuantity,
        ): void {
            $purchase = Purchase::withTrashed()->updateOrCreate(
                [
                    'merchant_id' => $merchant->id,
                    'purchase_no' => self::OPENING_PURCHASE_NO,
                ],
                [
                    'vendor_id' => $vendor->id,
                    'purchase_date' => now()->toDateString(),
                    'subtotal' => 0,
                    'total_amount' => 0,
                    'paid_amount' => 0,
                    'due_amount' => 0,
                    'payment_type' => 'credit',
                    'notes' => 'Stock imported from canteen stock sheet. Re-upload replaces this purchase.',
                    'created_by' => $createdBy,
                    'deleted_at' => null,
                ],
            );

            if ($purchase->trashed()) {
                $purchase->restore();
            }

            $purchase->items()->get()->each(function (PurchaseItem $item): void {
                $item->variants()->delete();
                $item->delete();
            });

            $subtotal = 0.0;
            $seenNames = [];

            foreach ($rows as $row) {
                $name = trim($row['name']);
                $normalized = $this->normalizeName($name);

                if ($normalized === '' || isset($seenNames[$normalized])) {
                    $skipped++;

                    continue;
                }

                $seenNames[$normalized] = true;

                $quantity = max(0, round((float) $row['quantity'], 2));
                $purchasePrice = max(0, round((float) $row['purchase_price'], 2));
                $sellingPrice = max(0, round((float) $row['selling_price'], 2));
                $totalQuantity += $quantity;

                $sku = $this->skuFor($normalized);
                $category = $this->categoryFor($merchant, $name);

                $existing = Product::withTrashed()
                    ->where('merchant_id', $merchant->id)
                    ->where('sku', $sku)
                    ->first();

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }

                    $existing->forceFill([
                        'name' => $name,
                        'category_id' => $category->id,
                        'purchase_price' => $purchasePrice,
                        'selling_price' => $sellingPrice,
                        'type' => 'stock',
                        'unit' => 'pcs',
                        'track_inventory' => true,
                        'is_variable_price' => false,
                        'is_active' => true,
                    ])->save();

                    $product = $existing;
                    $updated++;
                } else {
                    $product = Product::query()->create([
                        'id' => (string) Str::uuid(),
                        'merchant_id' => $merchant->id,
                        'sku' => $sku,
                        'name' => $name,
                        'category_id' => $category->id,
                        'purchase_price' => $purchasePrice,
                        'selling_price' => $sellingPrice,
                        'type' => 'stock',
                        'unit' => 'pcs',
                        'track_inventory' => true,
                        'is_variable_price' => false,
                        'is_active' => true,
                    ]);
                    $created++;
                }

                $product->businesses()->syncWithoutDetaching([$business->id]);
                $product->branches()->syncWithoutDetaching([$branch->id]);

                $variant = ProductVariant::withTrashed()
                    ->where('merchant_id', $merchant->id)
                    ->where('sku', $sku.'-STD')
                    ->first();

                if ($variant) {
                    if ($variant->trashed()) {
                        $variant->restore();
                    }

                    $variant->forceFill([
                        'product_id' => $product->id,
                        'name' => 'Standard',
                        'purchase_price' => $purchasePrice,
                        'selling_price' => $sellingPrice,
                        'is_active' => true,
                    ])->save();
                } else {
                    $variant = ProductVariant::query()->create([
                        'id' => (string) Str::uuid(),
                        'merchant_id' => $merchant->id,
                        'product_id' => $product->id,
                        'name' => 'Standard',
                        'sku' => $sku.'-STD',
                        'purchase_price' => $purchasePrice,
                        'selling_price' => $sellingPrice,
                        'is_active' => true,
                    ]);
                }

                $sold = ProductStockAvailability::soldQuantity($variant->id, $branch->id);
                $openingQty = max(0, round($quantity + $sold, 2));

                if ($openingQty <= 0) {
                    continue;
                }

                $lineTotal = round($openingQty * $purchasePrice, 2);
                $subtotal = round($subtotal + $lineTotal, 2);

                $purchaseItem = PurchaseItem::query()->create([
                    'id' => (string) Str::uuid(),
                    'purchase_id' => $purchase->id,
                    'business_id' => $business->id,
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'quantity' => $openingQty,
                    'unit_price' => $purchasePrice,
                    'line_total' => $lineTotal,
                    'discount' => 0,
                    'tax' => 0,
                ]);

                PurchaseItemVariant::query()->create([
                    'id' => (string) Str::uuid(),
                    'purchase_item_id' => $purchaseItem->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => $openingQty,
                    'unit_price' => $purchasePrice,
                    'line_total' => $lineTotal,
                ]);
            }

            $purchase->forceFill([
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
                'paid_amount' => 0,
                'due_amount' => $subtotal,
                'payment_type' => 'credit',
                'purchase_date' => now()->toDateString(),
                'notes' => 'Stock imported from canteen stock sheet. Re-upload replaces this purchase.',
            ])->save();
        });

        return [
            'products_created' => $created,
            'products_updated' => $updated,
            'rows_imported' => count($rows) - $skipped,
            'total_quantity' => $totalQuantity,
            'skipped' => $skipped,
            'items' => $rows,
        ];
    }

    /**
     * @return list<array{name: string, quantity: float, purchase_price: float, selling_price: float}>
     */
    public function parseSpreadsheet(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Stock file not found: {$path}");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = (int) $sheet->getHighestDataRow();

        $headerMap = null;
        $items = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            $values = [];
            foreach (range('A', 'H') as $column) {
                $values[$column] = $sheet->getCell($column.$row)->getCalculatedValue();
            }

            $normalizedHeaders = array_map(fn ($value) => $this->normalizeHeader((string) $value), $values);

            if ($headerMap === null && $this->looksLikeHeader($normalizedHeaders)) {
                $headerMap = $this->mapHeaders($normalizedHeaders);

                continue;
            }

            if ($headerMap === null) {
                continue;
            }

            $name = trim((string) ($values[$headerMap['name']] ?? ''));
            if ($name === '' || $this->normalizeHeader($name) === 'product name') {
                continue;
            }

            $quantity = $this->toFloat($values[$headerMap['quantity']] ?? 0);
            $purchasePrice = $this->toFloat($values[$headerMap['purchase_price']] ?? 0);
            $sellingPrice = $this->toFloat($values[$headerMap['selling_price']] ?? 0);

            if ($quantity < 0 && $purchasePrice <= 0 && $sellingPrice <= 0) {
                continue;
            }

            $items[] = [
                'name' => $name,
                'quantity' => max(0, $quantity),
                'purchase_price' => max(0, $purchasePrice),
                'selling_price' => max(0, $sellingPrice),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{name: string, quantity: string, purchase_price: string, selling_price: string}
     */
    private function mapHeaders(array $headers): array
    {
        $map = [
            'name' => null,
            'quantity' => null,
            'purchase_price' => null,
            'selling_price' => null,
        ];

        foreach ($headers as $column => $header) {
            if (in_array($header, ['product name', 'product', 'item', 'item name', 'name'], true)) {
                $map['name'] = $column;
            }
            if (in_array($header, ['qty', 'quantity', 'stock', 'stock qty'], true)) {
                $map['quantity'] = $column;
            }
            if (in_array($header, ['pr price', 'purchase price', 'buy price', 'cost', 'cost price'], true)) {
                $map['purchase_price'] = $column;
            }
            if (in_array($header, ['sell price', 'selling price', 'sale price', 'price'], true)) {
                $map['selling_price'] = $column;
            }
        }

        if ($map['name'] === null || $map['quantity'] === null) {
            throw new RuntimeException('Spreadsheet must include Product Name and Qty columns.');
        }

        $map['purchase_price'] ??= 'D';
        $map['selling_price'] ??= 'E';

        return $map;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function looksLikeHeader(array $headers): bool
    {
        $joined = implode(' ', $headers);

        return str_contains($joined, 'product') && str_contains($joined, 'qty');
    }

    private function normalizeHeader(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private function normalizeName(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    }

    private function skuFor(string $normalizedName): string
    {
        return 'STR-'.strtoupper(substr(md5($normalizedName), 0, 10));
    }

    private function toFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return 0.0;
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', $value) ?? '';

        return is_numeric($cleaned) ? (float) $cleaned : 0.0;
    }

    private function categoryFor(Merchant $merchant, string $productName): Category
    {
        $name = $this->guessCategoryName($productName);

        return Category::query()->firstOrCreate(
            [
                'merchant_id' => $merchant->id,
                'parent_id' => null,
                'name' => $name,
            ],
            [
                'id' => (string) Str::uuid(),
            ],
        );
    }

    private function guessCategoryName(string $productName): string
    {
        $needle = strtolower($productName);

        if (preg_match('/\b(book|workbook|islamiat|urdu|english|math|science|binding|cp\s*\d)\b/', $needle) === 1) {
            return 'Books';
        }

        if (preg_match('/\b(sock|sash|badge|belt|tie)\b/', $needle) === 1) {
            return 'Accessories';
        }

        return 'Uniforms';
    }

    private function ensureBusiness(Merchant $merchant): Business
    {
        return Business::query()->firstOrCreate(
            [
                'merchant_id' => $merchant->id,
                'name' => 'Strive Canteen',
            ],
            [
                'id' => (string) Str::uuid(),
                'description' => 'Uniform and bookshop canteen',
                'status' => true,
                'postal_code' => '54000',
            ],
        );
    }

    private function ensureBranch(Merchant $merchant, Business $business): Branch
    {
        return Branch::query()->firstOrCreate(
            [
                'merchant_id' => $merchant->id,
                'business_id' => $business->id,
                'name' => 'Main Canteen',
            ],
            [
                'id' => (string) Str::uuid(),
                'address' => $merchant->address_line_1 ?: 'Pakistan',
                'phone' => $merchant->phone,
                'status' => Branch::STATUS_VERIFIED,
                'is_active' => true,
                'postal_code' => '54000',
            ],
        );
    }

    private function ensureVendor(Merchant $merchant): Vendor
    {
        $countryId = Country::query()->where('code', 'PK')->value('id')
            ?? GeoFormFields::defaultCountryId();

        if (! $countryId) {
            throw new RuntimeException('No country found. Seed countries before importing stock.');
        }

        $cityId = City::query()
            ->where('country_id', $countryId)
            ->where('name', 'Lahore')
            ->value('id')
            ?? City::query()->where('country_id', $countryId)->value('id');

        return Vendor::query()->firstOrCreate(
            [
                'merchant_id' => $merchant->id,
                'name' => self::OPENING_VENDOR_NAME,
            ],
            [
                'id' => (string) Str::uuid(),
                'email' => 'opening-stock@strive.local',
                'phone' => null,
                'address' => 'Internal opening stock',
                'country_id' => $countryId,
                'city_id' => $cityId,
                'reference' => 'Stock sheet import',
                'postal_code' => '54000',
            ],
        );
    }
}
