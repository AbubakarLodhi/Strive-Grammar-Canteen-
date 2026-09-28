<?php

namespace Tests\Unit;

use App\Enums\LedgerAccountType;
use App\Models\City;
use App\Models\Country;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\Vendor;
use App\Services\Finance\FinanceLedger;
use App\Services\Inventory\CanteenStockImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceLedgerVendorAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_vendor_payable_account_in_the_chart_of_accounts(): void
    {
        $merchant = $this->createMerchant();
        $vendor = $this->createVendor($merchant, 'Paper House');

        $account = (new FinanceLedger)->ensureVendorPayableAccount($vendor);

        $this->assertSame($merchant->id, $account->merchant_id);
        $this->assertSame($vendor->id, $account->vendor_id);
        $this->assertSame('Paper House', $account->name);
        $this->assertSame(LedgerAccountType::Liability, $account->type);
        $this->assertSame('2001', $account->code);
        $this->assertTrue($account->isVendorPayable());
    }

    public function test_it_reuses_the_existing_vendor_payable_account(): void
    {
        $merchant = $this->createMerchant();
        $vendor = $this->createVendor($merchant, 'Paper House');
        $ledger = new FinanceLedger;

        $first = $ledger->ensureVendorPayableAccount($vendor);
        $second = $ledger->ensureVendorPayableAccount($vendor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LedgerAccount::query()->where('vendor_id', $vendor->id)->count());
    }

    public function test_it_updates_the_account_name_when_the_vendor_is_renamed(): void
    {
        $merchant = $this->createMerchant();
        $vendor = $this->createVendor($merchant, 'Old Name');
        $ledger = new FinanceLedger;

        $ledger->ensureVendorPayableAccount($vendor);

        $vendor->forceFill(['name' => 'New Name'])->save();

        $account = $ledger->ensureVendorPayableAccount($vendor->fresh());

        $this->assertSame('New Name', $account->name);
    }

    public function test_next_vendor_payable_code_skips_accounts_payable(): void
    {
        $ledger = new FinanceLedger;

        $this->assertSame('2001', $ledger->nextVendorPayableCode('merchant-id'));
    }

    public function test_it_rejects_opening_stock_vendor_payable_accounts(): void
    {
        $merchant = $this->createMerchant();
        $vendor = $this->createVendor($merchant, CanteenStockImporter::OPENING_VENDOR_NAME);
        $vendor->forceFill([
            'email' => CanteenStockImporter::OPENING_VENDOR_EMAIL,
            'reference' => CanteenStockImporter::OPENING_VENDOR_REFERENCE,
        ])->save();

        $this->expectException(ValidationException::class);

        (new FinanceLedger)->ensureVendorPayableAccount($vendor);
    }

    public function test_it_purges_opening_stock_ledger_artifacts(): void
    {
        $merchant = $this->createMerchant();
        $vendor = $this->createVendor($merchant, CanteenStockImporter::OPENING_VENDOR_NAME);
        $vendor->forceFill([
            'email' => CanteenStockImporter::OPENING_VENDOR_EMAIL,
            'reference' => CanteenStockImporter::OPENING_VENDOR_REFERENCE,
        ])->save();

        $account = LedgerAccount::query()->create([
            'merchant_id' => $merchant->id,
            'vendor_id' => $vendor->id,
            'code' => '2099',
            'name' => CanteenStockImporter::OPENING_VENDOR_NAME,
            'type' => LedgerAccountType::Liability,
            'is_bank' => false,
            'is_system' => false,
            'is_active' => true,
            'opening_balance' => 0,
        ]);

        (new FinanceLedger)->purgeOpeningStockLedger($merchant->id);

        $this->assertSoftDeleted($account);
    }

    public function test_provision_renames_legacy_purchases_account_to_cogs(): void
    {
        $merchant = $this->createMerchant();

        LedgerAccount::query()->create([
            'merchant_id' => $merchant->id,
            'code' => FinanceLedger::COGS_ACCOUNT_CODE,
            'name' => 'Purchases',
            'type' => LedgerAccountType::Expense,
            'is_bank' => false,
            'is_system' => true,
            'is_active' => true,
            'opening_balance' => 0,
        ]);

        (new FinanceLedger)->provisionDefaultAccounts($merchant);

        $account = LedgerAccount::query()
            ->where('merchant_id', $merchant->id)
            ->where('code', FinanceLedger::COGS_ACCOUNT_CODE)
            ->first();

        $this->assertNotNull($account);
        $this->assertSame('Cost of Goods Sold', $account->name);
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

    private function createVendor(Merchant $merchant, string $name): Vendor
    {
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

        return Vendor::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => $name,
            'email' => Str::uuid().'@example.com',
            'country_id' => $country->id,
            'city_id' => $city->id,
        ]);
    }
}
