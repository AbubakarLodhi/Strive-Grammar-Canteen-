<?php

namespace Tests\Feature;

use App\Filament\Resources\Sales\Pages\ListSales;
use App\Models\Branch;
use App\Models\Business;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\PermissionModule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemVariant;
use App\Models\SaleReturn;
use App\Services\SaleReturnService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SalesListPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_list_avoids_per_row_return_existence_queries(): void
    {
        [$merchant, $branch, $variant] = $this->seedMerchantCatalog();

        for ($i = 0; $i < 8; $i++) {
            $sale = $this->createPostedSale($merchant, $branch, $variant, total: 50 + $i);

            if ($i % 2 === 0) {
                SaleReturn::query()->create([
                    'id' => Str::uuid()->toString(),
                    'sale_id' => $sale->id,
                    'merchant_id' => $merchant->id,
                    'customer_id' => $sale->customer_id,
                    'return_no' => 'SR-'.Str::upper(Str::random(6)),
                    'return_date' => now()->toDateString(),
                    'subtotal' => 10,
                    'total_discount' => 0,
                    'total_tax' => 0,
                    'total_amount' => 10,
                ]);
            }
        }

        $this->actingAs($merchant, 'merchant');
        Filament::setCurrentPanel(Filament::getPanel('merchant'));

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(ListSales::class)
            ->assertSuccessful()
            ->assertSee('Total sale:', false);

        $perRowExistsQueries = collect(DB::getQueryLog())
            ->filter(function (array $query): bool {
                $sql = strtolower(trim($query['query']));

                // Standalone EXISTS checks (per-row), not withExists() select columns.
                return str_starts_with($sql, 'select exists(')
                    && str_contains($sql, 'sale_returns');
            })
            ->count();

        $this->assertSame(
            0,
            $perRowExistsQueries,
            'Sales list should not run per-row EXISTS queries against sale_returns.',
        );
    }

    public function test_sale_has_returns_uses_eager_loaded_relation(): void
    {
        [$merchant, $branch, $variant] = $this->seedMerchantCatalog();
        $sale = $this->createPostedSale($merchant, $branch, $variant, total: 100);

        SaleReturn::query()->create([
            'id' => Str::uuid()->toString(),
            'sale_id' => $sale->id,
            'merchant_id' => $merchant->id,
            'customer_id' => $sale->customer_id,
            'return_no' => 'SR-'.Str::upper(Str::random(6)),
            'return_date' => now()->toDateString(),
            'subtotal' => 10,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_amount' => 10,
        ]);

        $sale->load('returns');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue(SaleReturnService::saleHasReturns($sale));
        $this->assertTrue(SaleReturnService::hasReturnableItems($sale));
        $this->assertTrue(SaleReturnService::hasReturnableItems($sale));

        $queries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'sale_returns'))
            ->count();

        $this->assertSame(0, $queries);
    }

    /**
     * @return array{0: Merchant, 1: Branch, 2: ProductVariant}
     */
    private function seedMerchantCatalog(): array
    {
        $merchant = Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => Str::uuid().'@example.com',
            'name' => 'Test Merchant',
            'address_line_1' => '1 Test Street',
            'city' => 'Lahore',
            'status' => Merchant::STATUS_VERIFIED,
            'is_active' => true,
            'password' => 'password',
        ]);

        $salesModule = PermissionModule::query()->firstOrCreate(
            ['module' => 'sales'],
            [
                'id' => Str::uuid()->toString(),
                'label' => 'Sales',
            ],
        );
        $merchant->permissionModules()->attach($salesModule->id, [
            'id' => Str::uuid()->toString(),
        ]);

        $business = Business::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => 'Main Business',
            'is_active' => true,
        ]);

        $branch = Branch::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'business_id' => $business->id,
            'name' => 'Main Branch',
            'status' => Branch::STATUS_VERIFIED,
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => 'Test Product',
            'sku' => 'SKU-'.Str::upper(Str::random(6)),
            'selling_price' => 100,
            'purchase_price' => 50,
            'track_inventory' => true,
            'type' => 'product',
            'is_active' => true,
        ]);

        $variant = ProductVariant::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'product_id' => $product->id,
            'name' => 'Standard',
            'sku' => $product->sku.'-STD',
            'selling_price' => 100,
            'purchase_price' => 50,
            'is_active' => true,
        ]);

        return [$merchant, $branch, $variant];
    }

    private function createPostedSale(
        Merchant $merchant,
        Branch $branch,
        ProductVariant $variant,
        float $total,
    ): Sale {
        $country = Country::query()->firstOrCreate(
            ['code' => 'PK'],
            ['id' => Str::uuid()->toString(), 'name' => 'Pakistan'],
        );

        $city = City::query()->firstOrCreate(
            ['name' => 'Lahore', 'country_id' => $country->id],
            ['id' => Str::uuid()->toString()],
        );

        $customer = Customer::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => 'Customer '.Str::random(4),
            'email' => Str::uuid().'@example.com',
            'country_id' => $country->id,
            'city_id' => $city->id,
        ]);

        $sale = Sale::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'sale_no' => 'SAL-'.Str::upper(Str::random(6)),
            'sale_date' => now()->toDateString(),
            'subtotal' => $total,
            'total_amount' => $total,
            'paid_amount' => $total,
            'due_amount' => 0,
            'payment_type' => 'cash',
            'status' => Sale::STATUS_POSTED,
            'posted_at' => now(),
        ]);

        $item = SaleItem::query()->create([
            'id' => Str::uuid()->toString(),
            'sale_id' => $sale->id,
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'product_id' => $variant->product_id,
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
            'discount' => 0,
            'tax' => 0,
        ]);

        SaleItemVariant::query()->create([
            'id' => Str::uuid()->toString(),
            'sale_item_id' => $item->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        return $sale;
    }
}
