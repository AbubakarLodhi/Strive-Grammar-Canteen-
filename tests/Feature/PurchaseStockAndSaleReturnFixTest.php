<?php

namespace Tests\Feature;

use App\Enums\FinanceDocumentStatus;
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
use Illuminate\Support\Facades\DB;
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

    public function test_purchase_resolves_missing_branch_and_variant_for_stock(): void
    {
        [$merchant, $branch, $variant] = $this->seedStockedProduct(quantity: 0);
        $product = $variant->product;

        DB::table('branch_products')->insert([
            'id' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolvedBranchId = ProductStockAvailability::resolveBranchIdForProduct($product->id, null);
        $this->assertSame($branch->id, $resolvedBranchId);

        $resolvedVariantId = ProductStockAvailability::resolveVariantIdForProduct($product->id, null);
        $this->assertSame($variant->id, $resolvedVariantId);

        $purchase = Purchase::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'purchase_no' => 'PUR-SOCKS-1',
            'purchase_date' => now()->toDateString(),
            'subtotal' => 200,
            'total_amount' => 200,
            'paid_amount' => 200,
            'due_amount' => 0,
            'payment_type' => 'cash',
        ]);

        $branchModel = Branch::query()->find($resolvedBranchId);

        $purchaseItem = PurchaseItem::query()->create([
            'id' => Str::uuid()->toString(),
            'purchase_id' => $purchase->id,
            'business_id' => $branchModel->business_id,
            'branch_id' => $branchModel->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_price' => 20,
            'line_total' => 200,
            'discount' => 0,
            'tax' => 0,
        ]);

        $purchaseItem->variants()->create([
            'product_variant_id' => $resolvedVariantId,
            'quantity' => 10,
            'unit_price' => 20,
            'line_total' => 200,
        ]);

        $this->assertSame(10.0, ProductStockAvailability::variantStock($variant->id, $branch->id));
        $this->assertSame(10.0, ProductStockAvailability::productTotalStock($product->fresh(), $branch->id));
    }

    public function test_ensure_default_variant_creates_standard_when_missing(): void
    {
        $merchant = $this->createMerchant();

        $product = Product::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => 'Socks Large',
            'sku' => 'SOCK-L',
            'selling_price' => 100,
            'purchase_price' => 50,
            'track_inventory' => true,
            'type' => 'product',
            'is_active' => true,
        ]);

        $this->assertNull(ProductStockAvailability::defaultVariantIdForProduct($product->id));

        $variantId = ProductStockAvailability::ensureDefaultVariantIdForProduct($product->id);

        $this->assertNotNull($variantId);
        $this->assertDatabaseHas('product_variants', [
            'id' => $variantId,
            'product_id' => $product->id,
            'name' => 'Standard',
            'is_active' => true,
        ]);
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

        // Return voucher must not touch cash/AR — parent sale re-sync handles settlement.
        $this->assertSame(0.0, $arCredit);
        $this->assertSame(0.0, $cashCredit);

        $cashAfter = $this->postedAccountBalance($merchant->id, FinanceLedger::CASH_ACCOUNT_CODE);
        $arAfter = $this->postedAccountBalance($merchant->id, '1100');

        $this->assertSame($cashBefore, $cashAfter);
        $this->assertSame(100.0, $arAfter);
        $this->assertLessThan($arBefore, $arAfter);
        $this->assertSame(8.0, ProductStockAvailability::variantStock($variant->id, $branch->id));
    }

    public function test_full_cash_sale_return_does_not_double_subtract_cash_after_repair(): void
    {
        [$merchant, $branch, $variant] = $this->seedStockedProduct(quantity: 10);
        $sale = $this->createPostedSale($merchant, $branch, $variant, quantity: 4, paidAmount: 400);

        $cashAfterSale = $this->postedAccountBalance($merchant->id, FinanceLedger::CASH_ACCOUNT_CODE);
        $salesAfterSale = $this->postedAccountBalance($merchant->id, '4000');

        SaleReturnService::createReturn($sale->fresh(['items.product', 'items.variants', 'payments']), [
            'return_date' => now()->toDateString(),
            'reason' => 'Full return',
            'items' => [
                [
                    'sale_item_id' => $sale->items()->first()->id,
                    'quantity' => 4,
                ],
            ],
        ]);

        $sale->refresh();
        $this->assertSame(0.0, (float) $sale->total_amount);

        $cashAfterReturn = $this->postedAccountBalance($merchant->id, FinanceLedger::CASH_ACCOUNT_CODE);
        $salesAfterReturn = $this->postedAccountBalance($merchant->id, '4000');

        // Cash/sales should reverse once (back to pre-sale), not twice.
        $this->assertSame($cashAfterSale - 400.0, $cashAfterReturn);
        $this->assertSame($salesAfterSale - 400.0, $salesAfterReturn);

        $this->artisan('finance:repair-ledgers', [
            '--merchant' => $merchant->id,
        ])->assertSuccessful();

        $cashAfterRepair = $this->postedAccountBalance($merchant->id, FinanceLedger::CASH_ACCOUNT_CODE);
        $salesAfterRepair = $this->postedAccountBalance($merchant->id, '4000');

        $this->assertSame($cashAfterReturn, $cashAfterRepair);
        $this->assertSame($salesAfterReturn, $salesAfterRepair);
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

    public function test_vendor_purchase_posts_to_gl_and_allows_separate_manual_jv(): void
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

        app(FinanceLedger::class)->provisionDefaultAccounts($merchant);
        app(OperationalLedgerPoster::class)->syncPurchase($purchase->fresh(['payments', 'vendor']));

        $operational = JournalVoucher::query()
            ->where('source_type', $purchase->getMorphClass())
            ->where('source_id', $purchase->id)
            ->first();

        $this->assertNotNull($operational);
        $this->assertTrue($operational->isPosted());

        $manual = JournalVoucher::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'voucher_no' => 'JV-MANUAL-1',
            'voucher_date' => now()->toDateString(),
            'narration' => 'Manual JV — Purchase PUR-JV-1 — Paper House',
            'status' => FinanceDocumentStatus::Draft,
            'vendor_id' => $vendor->id,
        ]);

        $inventory = LedgerAccount::query()
            ->where('merchant_id', $merchant->id)
            ->where('code', FinanceLedger::INVENTORY_ACCOUNT_CODE)
            ->firstOrFail();
        $payable = app(FinanceLedger::class)->ensureVendorPayableAccount($vendor);

        $manual->lines()->createMany([
            [
                'ledger_account_id' => $inventory->id,
                'description' => 'Manual purchase inventory',
                'debit' => 500,
                'credit' => 0,
                'sort_order' => 1,
            ],
            [
                'ledger_account_id' => $payable->id,
                'description' => 'Manual purchase payable',
                'debit' => 0,
                'credit' => 500,
                'sort_order' => 2,
            ],
        ]);

        app(FinanceLedger::class)->postVoucher($manual->fresh(['lines']));

        $this->assertSame(
            2,
            JournalVoucher::query()
                ->where('merchant_id', $merchant->id)
                ->where(function ($query) use ($purchase, $manual): void {
                    $query
                        ->where(function ($q) use ($purchase): void {
                            $q->where('source_type', $purchase->getMorphClass())
                                ->where('source_id', $purchase->id);
                        })
                        ->orWhere('id', $manual->id);
                })
                ->count()
        );

        // Editing the purchase must refresh the operational voucher only.
        app(OperationalLedgerPoster::class)->syncPurchase($purchase->fresh(['payments', 'vendor']));
        $this->assertNull($manual->fresh()->source_id);
        $this->assertNotNull(
            JournalVoucher::query()
                ->where('source_type', $purchase->getMorphClass())
                ->where('source_id', $purchase->id)
                ->first()
        );

        $create = file_get_contents(app_path('Filament/Resources/Purchases/Pages/CreatePurchase.php'));
        $edit = file_get_contents(app_path('Filament/Resources/Purchases/Pages/EditPurchase.php'));
        $jvCreate = file_get_contents(app_path('Filament/Resources/JournalVouchers/Pages/CreateJournalVoucher.php'));

        $this->assertStringContainsString('syncPurchase(', $create);
        $this->assertStringContainsString('syncPurchase(', $edit);
        $this->assertStringNotContainsString('findVoucherForPurchase', $jvCreate);
        $this->assertStringNotContainsString("data['source_type']", $jvCreate);
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
