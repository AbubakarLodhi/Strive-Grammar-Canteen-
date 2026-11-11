<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ProductStockAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleProductOptionsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_product_options_avoids_per_product_stock_queries(): void
    {
        $merchant = Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => Str::uuid().'@example.com',
            'name' => 'Options Merchant',
            'address_line_1' => '1 Test Street',
            'city' => 'Lahore',
            'status' => Merchant::STATUS_VERIFIED,
            'is_active' => true,
            'password' => 'password',
        ]);

        $business = Business::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => 'Main Business',
            'is_active' => true,
        ]);

        Branch::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'business_id' => $business->id,
            'name' => 'Main Branch',
            'status' => Branch::STATUS_VERIFIED,
            'is_active' => true,
        ]);

        for ($i = 0; $i < 20; $i++) {
            $product = Product::query()->create([
                'id' => Str::uuid()->toString(),
                'merchant_id' => $merchant->id,
                'name' => 'Product '.$i,
                'sku' => 'SKU-'.$i.'-'.Str::upper(Str::random(4)),
                'selling_price' => 100,
                'purchase_price' => 50,
                'track_inventory' => true,
                'type' => 'product',
                'is_active' => true,
            ]);

            ProductVariant::query()->create([
                'id' => Str::uuid()->toString(),
                'merchant_id' => $merchant->id,
                'product_id' => $product->id,
                'name' => 'Standard',
                'sku' => $product->sku.'-STD',
                'selling_price' => 100,
                'purchase_price' => 50,
                'is_active' => true,
            ]);
        }

        ProductStockAvailability::clearRequestCaches();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $options = ProductStockAvailability::saleProductOptions($merchant->id);

        $queryCount = count(DB::getQueryLog());

        $this->assertCount(20, $options);
        $this->assertLessThan(
            15,
            $queryCount,
            'saleProductOptions should batch stock lookups instead of querying per product.',
        );

        $firstProductId = array_key_first($options);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertFalse(ProductStockAvailability::isProductIdAvailable($firstProductId));

        $availabilityQueries = count(DB::getQueryLog());

        $this->assertSame(
            0,
            $availabilityQueries,
            'isProductIdAvailable should reuse the warmed saleProductOptions cache.',
        );
    }

    public function test_sale_product_options_always_limits_results(): void
    {
        $merchant = Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => Str::uuid().'@example.com',
            'name' => 'Limit Merchant',
            'address_line_1' => '1 Test Street',
            'city' => 'Lahore',
            'status' => Merchant::STATUS_VERIFIED,
            'is_active' => true,
            'password' => 'password',
        ]);

        for ($i = 0; $i < 55; $i++) {
            Product::query()->create([
                'id' => Str::uuid()->toString(),
                'merchant_id' => $merchant->id,
                'name' => 'Bulk Product '.$i,
                'sku' => 'BULK-'.$i.'-'.Str::upper(Str::random(4)),
                'selling_price' => 10,
                'purchase_price' => 5,
                'track_inventory' => false,
                'type' => 'service',
                'is_active' => true,
            ]);
        }

        $options = ProductStockAvailability::saleProductOptions($merchant->id);

        $this->assertCount(50, $options);
    }
}
