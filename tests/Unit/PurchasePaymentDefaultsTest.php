<?php

namespace Tests\Unit;

use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Schemas\PurchaseForm;
use App\Services\Finance\FinanceLedger;
use App\Services\Finance\OperationalLedgerPoster;
use ReflectionMethod;
use Tests\TestCase;

class PurchasePaymentDefaultsTest extends TestCase
{
    public function test_blank_paid_amount_defaults_to_unpaid_credit_purchase(): void
    {
        $data = [
            'total_amount' => 64800,
            'paid_amount' => null,
        ];

        $method = new ReflectionMethod(CreatePurchase::class, 'applyPaymentFields');
        $method->invokeArgs(null, [&$data]);

        $this->assertSame(0.0, $data['paid_amount']);
        $this->assertSame(64800.0, $data['due_amount']);
        $this->assertSame('credit', $data['payment_type']);
    }

    public function test_blank_current_payment_does_not_auto_pay_full_total(): void
    {
        $state = [
            'total_amount' => 64800,
            'previous_paid_amount' => 0,
            'current_payment_amount' => null,
        ];

        $get = fn (string $key) => $state[$key] ?? null;
        $set = function (string $key, mixed $value) use (&$state): void {
            $state[$key] = $value;
        };

        $method = new ReflectionMethod(PurchaseForm::class, 'syncPaymentFromTotals');
        $method->setAccessible(true);
        $method->invoke(null, $set, $get);

        $this->assertSame(0.0, $state['current_payment_amount']);
        $this->assertSame(0.0, $state['paid_amount']);
        $this->assertSame(64800.0, $state['due_amount']);
        $this->assertSame('credit', $state['payment_type']);
    }

    public function test_unpaid_purchase_credits_vendor_payable_not_cash(): void
    {
        $lines = (new OperationalLedgerPoster(new FinanceLedger))->purchaseLinePlan(
            64800,
            0,
            64800,
            false,
            'Paper House',
            '2001',
            'PUR-64800',
        );

        $this->assertSame([
            ['code' => FinanceLedger::INVENTORY_ACCOUNT_CODE, 'debit' => 64800.0, 'credit' => 0, 'description' => 'Purchase PUR-64800 inventory — Paper House'],
            ['code' => '2001', 'debit' => 0, 'credit' => 64800.0, 'description' => 'Purchase PUR-64800 amount payable — Paper House'],
        ], $lines);

        $this->assertNull(collect($lines)->firstWhere('code', '1000'));
    }
}
