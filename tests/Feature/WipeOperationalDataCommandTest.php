<?php

namespace Tests\Feature;

use App\Console\Commands\WipeOperationalDataCommand;
use App\Models\Branch;
use App\Models\Business;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

class WipeOperationalDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_wipe_list_never_includes_branches_or_customers(): void
    {
        $reflection = new ReflectionClass(WipeOperationalDataCommand::class);
        $wipe = $reflection->getConstant('WIPE_TABLES');
        $preserve = $reflection->getConstant('PRESERVE_TABLES');

        $this->assertIsArray($wipe);
        $this->assertIsArray($preserve);
        $this->assertContains('branches', $preserve);
        $this->assertContains('customers', $preserve);
        $this->assertContains('customer_businesses', $preserve);
        $this->assertContains('customer_branches', $preserve);
        $this->assertNotContains('branches', $wipe);
        $this->assertNotContains('customers', $wipe);
        $this->assertNotContains('customer_businesses', $wipe);
        $this->assertNotContains('customer_branches', $wipe);
        $this->assertSame([], array_values(array_intersect($wipe, $preserve)));
    }

    public function test_wipe_keeps_merchant_branch_and_customer(): void
    {
        $merchant = Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => 'wipe-keep@example.com',
            'name' => 'Wipe Keep Merchant',
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
            'name' => 'Main Canteen',
            'status' => Branch::STATUS_VERIFIED,
            'is_active' => true,
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

        Sale::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'sale_no' => 'SALE-WIPE-1',
            'sale_date' => now()->toDateString(),
            'subtotal' => 100,
            'total_amount' => 100,
            'paid_amount' => 100,
            'due_amount' => 0,
            'status' => 'posted',
        ]);

        Purchase::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'purchase_no' => 'PUR-WIPE-1',
            'purchase_date' => now()->toDateString(),
            'subtotal' => 50,
            'total_amount' => 50,
            'paid_amount' => 0,
            'due_amount' => 50,
            'payment_type' => 'credit',
        ]);

        $this->artisan('app:wipe-operational-data', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('merchants', ['id' => $merchant->id, 'email' => 'wipe-keep@example.com']);
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'Main Canteen']);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Walk-in Customer']);
        $this->assertDatabaseMissing('sales', ['sale_no' => 'SALE-WIPE-1']);
        $this->assertDatabaseMissing('purchases', ['purchase_no' => 'PUR-WIPE-1']);
    }
}
