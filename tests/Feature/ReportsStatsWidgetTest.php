<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ReportsStatsWidget;
use App\Models\Branch;
use App\Models\Business;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseItemVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemVariant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class ReportsStatsWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_dashboard_date_range_spans_overview_chart_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01'));

        $defaults = Dashboard::defaultFilterDates();

        $this->assertSame('2026-05-01', $defaults['date_from']);
        $this->assertSame('2026-10-01', $defaults['date_to']);
    }

    public function test_overview_widgets_include_historical_sales_and_purchases(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00'));

        [$merchant, $branch, $variant] = $this->seedMerchantCatalog();

        $this->createPurchase(
            merchant: $merchant,
            branch: $branch,
            variant: $variant,
            quantity: 20,
            purchaseDate: '2026-09-04',
        );

        $this->createPostedSale(
            merchant: $merchant,
            branch: $branch,
            variant: $variant,
            quantity: 5,
            saleDate: '2026-09-28',
        );

        $this->createPostedSale(
            merchant: $merchant,
            branch: $branch,
            variant: $variant,
            quantity: 3,
            saleDate: '2026-08-27',
        );

        $this->actingAs($merchant, 'merchant');
        Filament::setCurrentPanel(Filament::getPanel('merchant'));

        $widget = new ReportsStatsWidget;
        $widget->pageFilters = Dashboard::defaultFilterDates();

        $trend = $this->invokeWidgetMethod($widget, 'getTrendData');
        $stock = $this->invokeWidgetMethod($widget, 'getStockStats');
        $returns = $this->invokeWidgetMethod($widget, 'getReturnStats');

        $this->assertSame(2, array_sum($trend['sales']));
        $this->assertSame(1, array_sum($trend['purchases']));
        $this->assertSame(8.0, $stock['total_sold_qty']);
        $this->assertSame(20.0, $stock['total_purchased_qty']);
        $this->assertSame(12.0, $stock['available_stock']);
        $this->assertSame(0, $returns['sales']['total_returns']);
        $this->assertSame(0, $returns['purchases']['total_returns']);
    }

    public function test_available_stock_ignores_narrow_date_filter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00'));

        [$merchant, $branch, $variant] = $this->seedMerchantCatalog();

        $this->createPurchase(
            merchant: $merchant,
            branch: $branch,
            variant: $variant,
            quantity: 15,
            purchaseDate: '2026-09-04',
        );

        $this->createPostedSale(
            merchant: $merchant,
            branch: $branch,
            variant: $variant,
            quantity: 4,
            saleDate: '2026-09-28',
        );

        $this->actingAs($merchant, 'merchant');
        Filament::setCurrentPanel(Filament::getPanel('merchant'));

        $widget = new ReportsStatsWidget;
        $widget->pageFilters = [
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-01',
        ];

        $stock = $this->invokeWidgetMethod($widget, 'getStockStats');
        $trend = $this->invokeWidgetMethod($widget, 'getTrendData');

        $this->assertSame(0, array_sum($trend['sales']));
        $this->assertSame(0, array_sum($trend['purchases']));
        $this->assertSame(0.0, $stock['total_sold_qty']);
        $this->assertSame(0.0, $stock['total_purchased_qty']);
        $this->assertSame(11.0, $stock['available_stock']);
    }

    /**
     * @return mixed
     */
    private function invokeWidgetMethod(ReportsStatsWidget $widget, string $method)
    {
        $reflection = new ReflectionMethod(ReportsStatsWidget::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($widget);
    }

    /**
     * @return array{0: Merchant, 1: Branch, 2: ProductVariant}
     */
    private function seedMerchantCatalog(): array
    {
        $merchant = Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => Str::uuid().'@example.com',
            'name' => 'Dashboard Merchant',
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

    private function createPurchase(
        Merchant $merchant,
        Branch $branch,
        ProductVariant $variant,
        float $quantity,
        string $purchaseDate,
    ): Purchase {
        $purchase = Purchase::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'purchase_no' => 'PUR-'.Str::upper(Str::random(6)),
            'purchase_date' => $purchaseDate,
            'subtotal' => $quantity * 50,
            'total_amount' => $quantity * 50,
            'paid_amount' => $quantity * 50,
            'due_amount' => 0,
            'payment_type' => 'cash',
        ]);

        $purchaseItem = PurchaseItem::query()->create([
            'id' => Str::uuid()->toString(),
            'purchase_id' => $purchase->id,
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'product_id' => $variant->product_id,
            'quantity' => $quantity,
            'unit_price' => 50,
            'line_total' => $quantity * 50,
            'discount' => 0,
            'tax' => 0,
        ]);

        PurchaseItemVariant::query()->create([
            'id' => Str::uuid()->toString(),
            'purchase_item_id' => $purchaseItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'unit_price' => 50,
            'line_total' => $quantity * 50,
        ]);

        return $purchase;
    }

    private function createPostedSale(
        Merchant $merchant,
        Branch $branch,
        ProductVariant $variant,
        float $quantity,
        string $saleDate,
    ): Sale {
        $country = Country::query()->first() ?? Country::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => 'Pakistan',
            'code' => 'PK',
        ]);

        $city = City::query()->first() ?? City::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => 'Lahore',
            'country_id' => $country->id,
        ]);

        $customer = Customer::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => 'Walk-in',
            'email' => Str::uuid().'@example.com',
            'country_id' => $country->id,
            'city_id' => $city->id,
        ]);

        $total = $quantity * 100;

        $sale = Sale::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'sale_no' => 'SAL-'.Str::upper(Str::random(6)),
            'sale_date' => $saleDate,
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
            'quantity' => $quantity,
            'unit_price' => 100,
            'line_total' => $total,
            'discount' => 0,
            'tax' => 0,
        ]);

        SaleItemVariant::query()->create([
            'id' => Str::uuid()->toString(),
            'sale_item_id' => $item->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'unit_price' => 100,
            'line_total' => $total,
        ]);

        return $sale;
    }
}
