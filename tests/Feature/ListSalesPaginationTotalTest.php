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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ListSalesPaginationTotalTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_list_shows_total_sale_next_to_pagination(): void
    {
        [$merchant, $branch, $variant] = $this->seedMerchantCatalog();

        $this->createPostedSale($merchant, $branch, $variant, total: 150.50);
        $this->createPostedSale($merchant, $branch, $variant, total: 49.50);
        $this->createPostedSale($merchant, $branch, $variant, total: 100.00, status: Sale::STATUS_DRAFT);

        $this->actingAs($merchant, 'merchant');
        Filament::setCurrentPanel(Filament::getPanel('merchant'));

        Livewire::test(ListSales::class)
            ->assertSuccessful()
            ->assertSee('Total sale: PKR 200.00', false)
            ->assertSee('Per page', false)
            ->assertDontSee('fi-sales-list-total', false)
            ->assertDontSee('Livewire.hook(\'morph.updated\'', false);
    }

    public function test_sales_list_keeps_unique_rows_after_date_filter_reset(): void
    {
        [$merchant, $branch, $variant] = $this->seedMerchantCatalog();

        $this->createPostedSale($merchant, $branch, $variant, total: 100.00);
        $this->createPostedSale($merchant, $branch, $variant, total: 200.00);

        $this->actingAs($merchant, 'merchant');
        Filament::setCurrentPanel(Filament::getPanel('merchant'));

        $component = Livewire::test(ListSales::class)
            ->assertSuccessful()
            ->set('tableDeferredFilters.sale_date_range.from_date', now()->toDateString())
            ->set('tableDeferredFilters.sale_date_range.to_date', now()->toDateString())
            ->call('applyTableFilters')
            ->call('removeTableFilters')
            ->assertSuccessful()
            ->assertDontSee('fi-sales-list-total', false);

        $ids = $component->instance()->getFilteredTableQuery()->pluck('id');

        $this->assertCount(2, $ids);
        $this->assertSame(2, $ids->unique()->count());
        $this->assertSame(
            'Total sale: PKR 300.00',
            $component->instance()->getTablePaginationTotalLabel(),
        );
    }

    public function test_sales_list_total_teleport_hook_is_not_registered(): void
    {
        $this->assertFileDoesNotExist(app_path('Filament/Support/SalesListTotalRenderHook.php'));
        $this->assertFileDoesNotExist(resource_path('views/filament/resources/sales/partials/sales-list-total.blade.php'));

        $merchantProvider = file_get_contents(app_path('Providers/Filament/MerchantPanelProvider.php'));
        $userProvider = file_get_contents(app_path('Providers/Filament/UserPanelProvider.php'));

        $this->assertStringNotContainsString('SalesListTotalRenderHook', $merchantProvider);
        $this->assertStringNotContainsString('SalesListTotalRenderHook', $userProvider);
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
        string $status = Sale::STATUS_POSTED,
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
            'status' => $status,
            'posted_at' => $status === Sale::STATUS_POSTED ? now() : null,
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
