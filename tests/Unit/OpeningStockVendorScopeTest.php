<?php

namespace Tests\Unit;

use App\Models\City;
use App\Models\Country;
use App\Models\Merchant;
use App\Models\Vendor;
use App\Services\Inventory\CanteenStockImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OpeningStockVendorScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_normal_vendors_with_null_reference_and_email(): void
    {
        [$merchant, $country, $city] = $this->createMerchantWithGeo();

        $normal = Vendor::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => 'Paper House',
            'email' => null,
            'reference' => null,
            'country_id' => $country->id,
            'city_id' => $city->id,
        ]);

        $opening = Vendor::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => CanteenStockImporter::OPENING_VENDOR_NAME,
            'email' => CanteenStockImporter::OPENING_VENDOR_EMAIL,
            'reference' => CanteenStockImporter::OPENING_VENDOR_REFERENCE,
            'country_id' => $country->id,
            'city_id' => $city->id,
        ]);

        $ids = CanteenStockImporter::scopeExcludeOpeningStockVendors(
            Vendor::query()->where('merchant_id', $merchant->id)
        )->pluck('id');

        $this->assertTrue($ids->contains($normal->id));
        $this->assertFalse($ids->contains($opening->id));
    }

    public function test_it_excludes_vendor_matching_any_opening_stock_marker(): void
    {
        [$merchant, $country, $city] = $this->createMerchantWithGeo();

        $byReference = Vendor::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => 'Something Else',
            'email' => 'other@example.com',
            'reference' => CanteenStockImporter::OPENING_VENDOR_REFERENCE,
            'country_id' => $country->id,
            'city_id' => $city->id,
        ]);

        $ids = CanteenStockImporter::scopeExcludeOpeningStockVendors(
            Vendor::query()->where('merchant_id', $merchant->id)
        )->pluck('id');

        $this->assertFalse($ids->contains($byReference->id));
    }

    /**
     * @return array{0: Merchant, 1: Country, 2: City}
     */
    private function createMerchantWithGeo(): array
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

        return [$merchant, $country, $city];
    }
}
