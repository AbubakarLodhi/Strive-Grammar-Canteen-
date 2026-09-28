<?php

namespace Tests\Feature;

use App\Filament\Resources\Sales\SaleResource;
use App\Models\Branch;
use App\Models\Business;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\JournalVoucher;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseItemVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemVariant;
use App\Services\Finance\OperationalLedgerPoster;
use App\Services\SalePostingService;
use App\Support\ProductStockAvailability;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleDraftPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_sale_does_not_affect_stock_payments_or_ledger(): void
    {
        [$merchant, $branch, $variant] = $this->seedStockedProduct(quantity: 10);

        $sale = $this->createSaleWithItem(
            merchant: $merchant,
            branch: $branch,
            variant: $variant,
            quantity: 3,
            status: Sale::STATUS_DRAFT,
            paidAmount: 300,
        );

        app(OperationalLedgerPoster::class)->syncSale($sale);

        $this->assertTrue($sale->isDraft());
        $this->assertSame(0, Payment::query()->where('paymentable_id', $sale->id)->count());
        $this->assertSame(0, JournalVoucher::query()
            ->where('source_type', $sale->getMorphClass())
            ->where('source_id', $sale->id)
            ->count());
        $this->assertSame(10.0, ProductStockAvailability::variantStock($variant->id, $branch->id));
    }

    public function test_pushing_draft_sale_applies_payment_ledger_and_stock(): void
    {
        [$merchant, $branch, $variant] = $this->seedStockedProduct(quantity: 10);

        $sale = $this->createSaleWithItem(
            merchant: $merchant,
            branch: $branch,
            variant: $variant,
            quantity: 3,
            status: Sale::STATUS_DRAFT,
            paidAmount: 300,
        );

        $posted = app(SalePostingService::class)->post($sale);

        $this->assertTrue($posted->isPosted());
        $this->assertNotNull($posted->posted_at);
        $this->assertSame(1, Payment::query()->where('paymentable_id', $posted->id)->count());
        $this->assertSame(1, JournalVoucher::query()
            ->where('source_type', $posted->getMorphClass())
            ->where('source_id', $posted->id)
            ->count());
        $this->assertSame(7.0, ProductStockAvailability::variantStock($variant->id, $branch->id));
    }

    public function test_posted_sales_still_reduce_stock(): void
    {
        [$merchant, $branch, $variant] = $this->seedStockedProduct(quantity: 5);

        $sale = $this->createSaleWithItem(
            merchant: $merchant,
            branch: $branch,
            variant: $variant,
            quantity: 2,
            status: Sale::STATUS_POSTED,
            paidAmount: 200,
        );

        app(OperationalLedgerPoster::class)->syncSale($sale);

        $this->assertSame(3.0, ProductStockAvailability::variantStock($variant->id, $branch->id));
        $this->assertSame(1, JournalVoucher::query()
            ->where('source_type', $sale->getMorphClass())
            ->where('source_id', $sale->id)
            ->count());
    }

    public function test_draft_sale_is_resolvable_for_view_and_invoice(): void
    {
        [$merchant, $branch, $variant] = $this->seedStockedProduct(quantity: 5);

        $draft = $this->createSaleWithItem(
            merchant: $merchant,
            branch: $branch,
            variant: $variant,
            quantity: 1,
            status: Sale::STATUS_DRAFT,
            paidAmount: 0,
        );

        $this->actingAs($merchant, 'merchant');
        Filament::setCurrentPanel(Filament::getPanel('merchant'));

        $resolved = SaleResource::getEloquentQuery()->whereKey($draft->id)->first();

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->isDraft());

        $this->get(route('invoices.show', [
            'type' => 'sale',
            'id' => $draft->id,
        ]))->assertOk();
    }

    /**
     * @return array{0: Merchant, 1: Branch, 2: ProductVariant}
     */
    private function seedStockedProduct(float $quantity): array
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

        $purchase = Purchase::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'purchase_no' => 'PUR-TEST-1',
            'purchase_date' => now()->toDateString(),
            'subtotal' => $quantity * 50,
            'total_amount' => $quantity * 50,
            'paid_amount' => $quantity * 50,
            'due_amount' => 0,
            'payment_type' => 'cash',
        ]);

        $purchaseItem = PurchaseItem::query()->create([
            'id' => Str::uuid()->toString(),
            'purchase_id' => $purchase->id,
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
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

        return [$merchant, $branch, $variant];
    }

    private function createSaleWithItem(
        Merchant $merchant,
        Branch $branch,
        ProductVariant $variant,
        float $quantity,
        string $status,
        float $paidAmount,
    ): Sale {
        $country = Country::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => 'Pakistan',
            'code' => 'PK',
        ]);

        $city = City::query()->create([
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
            'sale_date' => now()->toDateString(),
            'subtotal' => $total,
            'total_amount' => $total,
            'paid_amount' => $paidAmount,
            'due_amount' => max(0, $total - $paidAmount),
            'payment_type' => $paidAmount < $total ? 'credit' : 'cash',
            'status' => $status,
            'posted_at' => $status === Sale::STATUS_POSTED ? now() : null,
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

        return $sale->fresh(['items.variants', 'payments']) ?? $sale;
    }
}
