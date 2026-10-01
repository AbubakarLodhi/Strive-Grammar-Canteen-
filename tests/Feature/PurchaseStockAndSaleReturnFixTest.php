<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemVariant;
use App\Models\Vendor;
use App\Services\Finance\FinanceLedger;
use App\Services\Finance\OperationalLedgerPoster;
use App\Services\PaymentLedgerService;
use App\Services\SaleReturnService;
use App\Support\ProductStockAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseStockAndSaleReturnFixTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_without_selected_variant_still_updates_stock(): void
    {
        [$merchant, $branch, $variant] = $this->seedStockedProduct(quantity: 0);

        $purchase = Purchase::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'purchase_no' => 'PUR-STOCK-1',
            'purchase_date' => now()->toDateString(),
            'subtotal' => 500,
            'total_amount' => 500,
            'paid_amount' => 500,
            'due_amount' => 0,
            'payment_type' => 'cash',
        ]);

        $purchaseItem = PurchaseItem::query()->create([
            'id' => Str::uuid()->toString(),
            'purchase_id' => $purchase->id,
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'product_id' => $variant->product_id,
            'quantity' => 5,
            'unit_price' => 100,
            'line_total' => 500,
            'discount' => 0,
            'tax' => 0,
        ]);

        $resolvedVariantId = ProductStockAvailability::resolveVariantIdForProduct(
            $variant->product_id,
            null,
        );

        $this->assertSame($variant->id, $resolvedVariantId);

        $purchaseItem->variants()->create([
            'product_variant_id' => $resolvedVariantId,
            'quantity' => 5,
            'unit_price' => 100,
            'line_total' => 500,
        ]);

        $this->assertSame(5.0, ProductStockAvailability::variantStock($variant->id, $branch->id));
    }

    public function test_credit_sale_return_reduces_receivable_instead_of_draining_cash(): void
    {
        [$merchant, $branch, $variant] = $this->seedStockedProduct(quantity: 10);
        $sale = $this->createPostedSale($merchant, $branch, $variant, quantity: 4, paidAmount: 100);

        $this->assertSame(300.0, (float) $sale->due_amount);

        $cashBefore = $this->postedAccountBalance($merchant->id, FinanceLedger::CASH_ACCOUNT_CODE);
        $arBefore = $this->postedAccountBalance($merchant->id, '1100');

        SaleReturnService::createReturn($sale->fresh(['items.product', 'items.variants', 'payments']), [
            'return_date' => now()->toDateString(),
            'reason' => 'Damaged',
            'items' => [
                [
                    'sale_item_id' => $sale->items()->first()->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $return = $sale->returns()->latest('created_at')->first();
        $this->assertNotNull($return);

        $voucher = JournalVoucher::query()
            ->where('source_type', $return->getMorphClass())
            ->where('source_id', $return->id)
            ->with('lines.ledgerAccount')
            ->first();

        $this->assertNotNull($voucher);

        $arCredit = (float) $voucher->lines
            ->filter(fn (JournalVoucherLine $line) => $line->ledgerAccount?->code === '1100')
            ->sum('credit');
        $cashCredit = (float) $voucher->lines
            ->filter(fn (JournalVoucherLine $line) => $line->ledgerAccount?->code === FinanceLedger::CASH_ACCOUNT_CODE)
            ->sum('credit');

        $this->assertSame(200.0, $arCredit);
        $this->assertSame(0.0, $cashCredit);

        $cashAfter = $this->postedAccountBalance($merchant->id, FinanceLedger::CASH_ACCOUNT_CODE);
        $arAfter = $this->postedAccountBalance($merchant->id, '1100');

        $this->assertSame($cashBefore, $cashAfter);
        $this->assertLessThan($arBefore, $arAfter);
        $this->assertSame(8.0, ProductStockAvailability::variantStock($variant->id, $branch->id));
    }

    public function test_creating_vendor_creates_party_ledger_account(): void
    {
        $merchant = $this->createMerchant();
        [$country, $city] = $this->createGeo();

        $vendor = Vendor::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => 'New Party Vendor',
            'email' => Str::uuid().'@example.com',
            'phone' => null,
            'country_id' => $country->id,
            'city_id' => $city->id,
        ]);

        $account = app(FinanceLedger::class)->ensureVendorPayableAccount($vendor);

        $this->assertSame($vendor->id, $account->vendor_id);
        $this->assertSame('New Party Vendor', $account->name);
        $this->assertTrue(
            LedgerAccount::query()->where('vendor_id', $vendor->id)->exists()
        );
    }

    public function test_vendor_purchase_does_not_auto_create_journal_voucher(): void
    {
        [$merchant, $branch, $variant] = $this->seedStockedProduct(quantity: 0);
        [$country, $city] = $this->createGeo();

        $vendor = Vendor::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => 'Paper House',
            'email' => Str::uuid().'@example.com',
            'country_id' => $country->id,
            'city_id' => $city->id,
        ]);

        $purchase = Purchase::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'vendor_id' => $vendor->id,
            'purchase_no' => 'PUR-JV-1',
            'purchase_date' => now()->toDateString(),
            'subtotal' => 500,
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'payment_type' => 'credit',
        ]);

        PurchaseItem::query()->create([
            'id' => Str::uuid()->toString(),
            'purchase_id' => $purchase->id,
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'product_id' => $variant->product_id,
            'quantity' => 5,
            'unit_price' => 100,
            'line_total' => 500,
            'discount' => 0,
            'tax' => 0,
        ])->variants()->create([
            'product_variant_id' => $variant->id,
            'quantity' => 5,
            'unit_price' => 100,
            'line_total' => 500,
        ]);

        $this->assertFalse(
            JournalVoucher::query()
                ->where('source_type', Purchase::class)
                ->where('source_id', $purchase->id)
                ->exists()
        );

        $create = file_get_contents(app_path('Filament/Resources/Purchases/Pages/CreatePurchase.php'));
        $edit = file_get_contents(app_path('Filament/Resources/Purchases/Pages/EditPurchase.php'));

        $this->assertStringNotContainsString('syncPurchase(', $create);
        $this->assertStringNotContainsString('syncPurchase(', $edit);
    }

    /**
     * @return array{0: Merchant, 1: Branch, 2: ProductVariant}
     */
    private function seedStockedProduct(float $quantity): array
    {
        $merchant = $this->createMerchant();

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

        if ($quantity > 0) {
            $purchase = Purchase::query()->create([
                'id' => Str::uuid()->toString(),
                'merchant_id' => $merchant->id,
                'purchase_no' => 'PUR-SEED-1',
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

            $purchaseItem->variants()->create([
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
                'unit_price' => 50,
                'line_total' => $quantity * 50,
            ]);
        }

        return [$merchant, $branch, $variant];
    }

    private function createPostedSale(
        Merchant $merchant,
        Branch $branch,
        ProductVariant $variant,
        float $quantity,
        float $paidAmount,
    ): Sale {
        [$country, $city] = $this->createGeo();

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

        if ($paidAmount > 0) {
            PaymentLedgerService::recordSalePayment(
                $sale,
                $paidAmount,
                now()->toDateString(),
                'payment',
                null,
                null,
                'cash',
            );
        }

        app(OperationalLedgerPoster::class)->syncSale($sale->fresh(['payments', 'items.product']));

        return $sale->fresh(['items.product', 'items.variants', 'payments']);
    }

    private function postedAccountBalance(string $merchantId, string $code): float
    {
        $account = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('code', $code)
            ->first();

        return $account ? (float) $account->postedBalance() : 0.0;
    }

    private function createMerchant(): Merchant
    {
        return Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => Str::uuid().'@example.com',
            'name' => 'Test Merchant',
            'address_line_1' => '1 Test Street',
            'city' => 'Lahore',
            'status' => Merchant::STATUS_VERIFIED,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    /**
     * @return array{0: Country, 1: City}
     */
    private function createGeo(): array
    {
        $country = Country::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => 'Pakistan',
            'code' => 'PK'.Str::upper(Str::random(2)),
        ]);

        $city = City::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => 'Lahore',
            'country_id' => $country->id,
        ]);

        return [$country, $city];
    }
}
