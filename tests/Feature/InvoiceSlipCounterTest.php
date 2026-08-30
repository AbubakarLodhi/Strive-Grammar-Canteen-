<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Services\InvoiceSlipCounterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceSlipCounterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_starts_daily_slip_numbers_at_one(): void
    {
        $merchant = $this->createMerchant();
        $service = app(InvoiceSlipCounterService::class);

        $this->assertSame(1, $service->nextNumber($merchant->id));
    }

    public function test_it_increments_slip_numbers_for_the_same_day(): void
    {
        $merchant = $this->createMerchant();
        $service = app(InvoiceSlipCounterService::class);

        $this->assertSame(1, $service->nextNumber($merchant->id));
        $this->assertSame(2, $service->nextNumber($merchant->id));
        $this->assertSame(3, $service->nextNumber($merchant->id));
    }

    public function test_it_resets_slip_numbers_on_a_new_day(): void
    {
        Carbon::setTestNow('2026-08-30 10:00:00');

        $merchant = $this->createMerchant();
        $service = app(InvoiceSlipCounterService::class);

        $this->assertSame(1, $service->nextNumber($merchant->id));
        $this->assertSame(2, $service->nextNumber($merchant->id));

        Carbon::setTestNow('2026-08-31 09:00:00');

        $this->assertSame(1, $service->nextNumber($merchant->id));
    }

    public function test_merchants_have_independent_daily_slip_numbers(): void
    {
        $firstMerchant = $this->createMerchant();
        $secondMerchant = $this->createMerchant();
        $service = app(InvoiceSlipCounterService::class);

        $this->assertSame(1, $service->nextNumber($firstMerchant->id));
        $this->assertSame(1, $service->nextNumber($secondMerchant->id));
        $this->assertSame(2, $service->nextNumber($firstMerchant->id));
    }

    private function createMerchant(): Merchant
    {
        return Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => Str::uuid().'@example.com',
            'name' => 'Test Merchant',
            'status' => Merchant::STATUS_VERIFIED,
            'is_active' => true,
            'password' => 'password',
        ]);
    }
}
