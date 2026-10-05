<?php

namespace Tests\Unit;

use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Merchant;
use App\Support\PhoneFormFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OptionalPhoneFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_be_created_without_phone(): void
    {
        $merchant = Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => Str::uuid().'@example.com',
            'name' => 'Phone Optional Merchant',
            'address_line_1' => '1 Test Street',
            'city' => 'Lahore',
            'status' => Merchant::STATUS_VERIFIED,
            'is_active' => true,
            'password' => 'password',
            'phone' => null,
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

        $customer = Customer::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => 'Walk-in Customer',
            'phone' => null,
            'email' => null,
            'country_id' => $country->id,
            'city_id' => $city->id,
        ]);

        $this->assertNull($customer->fresh()->phone);
    }

    public function test_blank_and_country_prefix_phone_values_are_treated_as_optional(): void
    {
        $this->assertTrue(PhoneFormFields::isBlank(null));
        $this->assertTrue(PhoneFormFields::isBlank(''));
        $this->assertTrue(PhoneFormFields::isBlank('+92'));
        $this->assertTrue(PhoneFormFields::isBlank(' +92 '));
        $this->assertFalse(PhoneFormFields::isBlank('+923001234567'));

        $this->assertNull(PhoneFormFields::normalize('+92'));
        $this->assertSame('+923001234567', PhoneFormFields::normalize('+923001234567'));

        $failCalled = false;
        $rule = PhoneFormFields::optionalPakistanPhoneRule();
        $rule->validate('phone', '+92', function () use (&$failCalled): void {
            $failCalled = true;
        });
        $this->assertFalse($failCalled);

        $failCalled = false;
        $rule->validate('phone', '+92300', function () use (&$failCalled): void {
            $failCalled = true;
        });
        $this->assertTrue($failCalled);
    }

    public function test_customer_and_vendor_forms_use_shared_optional_phone_field(): void
    {
        $customerForm = file_get_contents(app_path('Filament/Resources/Customers/Schemas/CustomerForm.php'));
        $vendorForm = file_get_contents(app_path('Filament/Resources/Vendors/Schemas/VendorForm.php'));
        $branchForm = file_get_contents(app_path('Filament/Resources/Branches/Schemas/BranchForm.php'));
        $merchantForm = file_get_contents(app_path('Filament/Resources/Merchants/Schemas/MerchantForm.php'));

        $this->assertStringContainsString('PhoneFormFields::optionalPakistanPhone()', $customerForm);
        $this->assertStringContainsString('PhoneFormFields::optionalPakistanPhone()', $vendorForm);
        $this->assertStringContainsString('->nullable()', $branchForm);
        $this->assertStringContainsString('Optional', $branchForm);
        $this->assertStringContainsString('->nullable()', $merchantForm);
        $this->assertStringContainsString('Optional', $merchantForm);
    }
}
