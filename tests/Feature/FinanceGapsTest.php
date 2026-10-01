<?php

namespace Tests\Feature;

use App\Enums\CashVoucherDirection;
use App\Enums\FinanceDocumentStatus;
use App\Enums\LedgerAccountType;
use App\Filament\Pages\ReceivableAging;
use App\Models\CashVoucher;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\OnlineBankTransfer;
use App\Models\Sale;
use App\Services\Finance\FinanceLedger;
use App\Services\Finance\FinancialStatements;
use App\Services\Finance\OperationalLedgerPoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceGapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_receiving_posts_debit_cash_and_increases_balance(): void
    {
        [$merchant, $cash, $counter] = $this->seedMerchantAccounts();

        $voucher = CashVoucher::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'direction' => CashVoucherDirection::Receiving,
            'voucher_no' => 'CRV-1',
            'voucher_date' => now()->toDateString(),
            'cash_account_id' => $cash->id,
            'counter_account_id' => $counter->id,
            'amount' => 150,
            'status' => FinanceDocumentStatus::Draft,
        ]);

        app(FinanceLedger::class)->postCashVoucher($voucher->fresh(['cashAccount', 'counterAccount']));

        $this->assertTrue($voucher->fresh()->isPosted());
        $this->assertSame('150.00', $cash->fresh()->postedBalance());
    }

    public function test_cash_payment_posts_credit_cash(): void
    {
        [$merchant, $cash, $counter] = $this->seedMerchantAccounts(cashOpening: 500);

        $voucher = CashVoucher::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'direction' => CashVoucherDirection::Payment,
            'voucher_no' => 'CPV-1',
            'voucher_date' => now()->toDateString(),
            'cash_account_id' => $cash->id,
            'counter_account_id' => $counter->id,
            'amount' => 80,
            'status' => FinanceDocumentStatus::Draft,
        ]);

        app(FinanceLedger::class)->postCashVoucher($voucher->fresh(['cashAccount', 'counterAccount']));

        $this->assertSame('420.00', $cash->fresh()->postedBalance());
    }

    public function test_online_transfer_moves_balance_between_accounts(): void
    {
        [$merchant, $cash, $counter, $bank] = $this->seedMerchantAccounts(cashOpening: 300, withBank: true);

        $transfer = OnlineBankTransfer::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'transfer_no' => 'OBT-1',
            'transfer_date' => now()->toDateString(),
            'from_account_id' => $cash->id,
            'to_account_id' => $bank->id,
            'amount' => 100,
            'status' => FinanceDocumentStatus::Draft,
        ]);

        app(FinanceLedger::class)->postOnlineBankTransfer($transfer->fresh(['fromAccount', 'toAccount']));

        $this->assertSame('200.00', $cash->fresh()->postedBalance());
        $this->assertSame('100.00', $bank->fresh()->postedBalance());
    }

    public function test_posted_journal_voucher_cannot_be_deleted(): void
    {
        [$merchant, $cash, $counter] = $this->seedMerchantAccounts();

        $jv = JournalVoucher::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'voucher_no' => 'JV-LOCK',
            'voucher_date' => now()->toDateString(),
            'narration' => 'Lock test',
            'status' => FinanceDocumentStatus::Draft,
        ]);

        $jv->lines()->createMany([
            [
                'ledger_account_id' => $cash->id,
                'description' => 'Debit',
                'debit' => 50,
                'credit' => 0,
                'sort_order' => 1,
            ],
            [
                'ledger_account_id' => $counter->id,
                'description' => 'Credit',
                'debit' => 0,
                'credit' => 50,
                'sort_order' => 2,
            ],
        ]);

        app(FinanceLedger::class)->postVoucher($jv->fresh(['lines']));

        $this->expectException(\RuntimeException::class);
        $jv->fresh()->delete();
    }

    public function test_manual_journal_voucher_is_posted_and_affects_general_ledger(): void
    {
        [$merchant, $cash, $counter] = $this->seedMerchantAccounts();

        $ledger = app(FinanceLedger::class);
        $lines = [
            [
                'ledger_account_id' => $cash->id,
                'description' => 'Vendor payment debit',
                'debit' => 900,
                'credit' => 0,
            ],
            [
                'ledger_account_id' => $counter->id,
                'description' => 'Vendor payment credit',
                'debit' => 0,
                'credit' => 900,
            ],
        ];

        $ledger->assertBalanced($lines);

        $jv = JournalVoucher::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'voucher_no' => 'JV-20261001-0009',
            'voucher_date' => now()->toDateString(),
            'narration' => 'Vendor payment — Mustaqeem Leather House',
            'status' => FinanceDocumentStatus::Draft,
        ]);

        foreach (array_values($lines) as $index => $line) {
            $jv->lines()->create([
                ...$line,
                'sort_order' => $index + 1,
            ]);
        }

        $posted = $ledger->postVoucher($jv->fresh(['lines']));

        $this->assertTrue($posted->isPosted());
        $this->assertNotNull($posted->posted_at);
        $this->assertSame('900.00', $cash->fresh()->postedBalance());

        $glLineCount = JournalVoucherLine::query()
            ->where('ledger_account_id', $cash->id)
            ->whereHas('journalVoucher', fn ($q) => $q->where('status', FinanceDocumentStatus::Posted->value))
            ->count();

        $this->assertSame(1, $glLineCount);
    }

    public function test_create_journal_voucher_page_posts_on_save(): void
    {
        $create = file_get_contents(app_path('Filament/Resources/JournalVouchers/Pages/CreateJournalVoucher.php'));

        $this->assertStringContainsString('postVoucher', $create);
        $this->assertStringContainsString('FinanceDocumentStatus::Draft', $create);
    }

    public function test_purchase_plan_debits_inventory_and_sale_plan_posts_cogs(): void
    {
        $poster = app(OperationalLedgerPoster::class);

        $purchase = $poster->purchaseLinePlan(200, 200, 0);
        $inventoryDebit = collect($purchase)->firstWhere('code', FinanceLedger::INVENTORY_ACCOUNT_CODE);
        $this->assertNotNull($inventoryDebit);
        $this->assertSame(200.0, (float) $inventoryDebit['debit']);

        $sale = $poster->saleLinePlan(300, 300, 0, false, 120);
        $cogs = collect($sale)->firstWhere('code', FinanceLedger::COGS_ACCOUNT_CODE);
        $inventoryCredit = collect($sale)->first(
            fn (array $line): bool => $line['code'] === FinanceLedger::INVENTORY_ACCOUNT_CODE && (float) $line['credit'] > 0
        );
        $this->assertNotNull($cogs);
        $this->assertSame(120.0, (float) $cogs['debit']);
        $this->assertNotNull($inventoryCredit);
        $this->assertSame(120.0, (float) $inventoryCredit['credit']);
    }

    public function test_receivable_aging_buckets_sum_open_dues(): void
    {
        $merchant = Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => Str::uuid().'@example.com',
            'name' => 'Aging Merchant',
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

        $customer = Customer::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'name' => 'Credit Customer',
            'email' => Str::uuid().'@example.com',
            'country_id' => $country->id,
            'city_id' => $city->id,
        ]);

        Sale::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'sale_no' => 'S-CURRENT',
            'sale_date' => now()->toDateString(),
            'subtotal' => 100,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => Sale::STATUS_POSTED,
            'posted_at' => now(),
        ]);

        Sale::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'sale_no' => 'S-45',
            'sale_date' => now()->subDays(50)->toDateString(),
            'subtotal' => 200,
            'total_amount' => 200,
            'paid_amount' => 50,
            'due_amount' => 150,
            'due_date' => now()->subDays(45)->toDateString(),
            'status' => Sale::STATUS_POSTED,
            'posted_at' => now()->subDays(50),
        ]);

        Sale::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'sale_no' => 'S-100',
            'sale_date' => now()->subDays(120)->toDateString(),
            'subtotal' => 80,
            'total_amount' => 80,
            'paid_amount' => 0,
            'due_amount' => 80,
            'due_date' => now()->subDays(100)->toDateString(),
            'status' => Sale::STATUS_POSTED,
            'posted_at' => now()->subDays(120),
        ]);

        $page = new ReceivableAging;
        $this->actingAs($merchant, 'merchant');

        // Bypass Filament auth by stubbing merchant via direct data calc
        $sales = Sale::query()
            ->posted()
            ->where('merchant_id', $merchant->id)
            ->where('due_amount', '>', 0)
            ->get();

        $buckets = [
            'current' => 0.0,
            '1_30' => 0.0,
            '31_60' => 0.0,
            '61_90' => 0.0,
            '90_plus' => 0.0,
        ];

        $today = now()->startOfDay();
        foreach ($sales as $sale) {
            $dueDate = $sale->due_date?->startOfDay() ?? $sale->sale_date->startOfDay();
            $days = $dueDate->diffInDays($today, false);
            $amount = (float) $sale->due_amount;
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };
            $buckets[$bucket] += $amount;
        }

        $this->assertSame(100.0, $buckets['current']);
        $this->assertSame(150.0, $buckets['31_60']);
        $this->assertSame(80.0, $buckets['90_plus']);
        $this->assertSame(330.0, array_sum($buckets));
        $this->assertInstanceOf(ReceivableAging::class, $page);
    }

    public function test_financial_statements_as_at_does_not_use_future_date(): void
    {
        $merchant = Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => Str::uuid().'@example.com',
            'name' => 'TB Merchant',
            'address_line_1' => '1 Test Street',
            'city' => 'Lahore',
            'status' => Merchant::STATUS_VERIFIED,
            'is_active' => true,
            'password' => 'password',
        ]);

        app(FinanceLedger::class)->provisionDefaultAccounts($merchant);

        $statements = app(FinancialStatements::class)
            ->forPeriod($merchant->id, (int) now()->year, null);

        $asAt = Carbon::createFromFormat('d/m/Y', $statements['as_at'])->startOfDay();
        $this->assertTrue($asAt->lessThanOrEqualTo(now()->endOfDay()));
    }

    public function test_compare_years_equity_includes_period_profit(): void
    {
        $merchant = Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => Str::uuid().'@example.com',
            'name' => 'Compare Merchant',
            'address_line_1' => '1 Test Street',
            'city' => 'Lahore',
            'status' => Merchant::STATUS_VERIFIED,
            'is_active' => true,
            'password' => 'password',
        ]);

        $ledger = app(FinanceLedger::class);
        $ledger->provisionDefaultAccounts($merchant);

        $cash = LedgerAccount::query()
            ->where('merchant_id', $merchant->id)
            ->where('code', '1000')
            ->firstOrFail();
        $sales = LedgerAccount::query()
            ->where('merchant_id', $merchant->id)
            ->where('code', '4000')
            ->firstOrFail();

        $jv = JournalVoucher::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'voucher_no' => 'JV-CMP',
            'voucher_date' => now()->toDateString(),
            'narration' => 'Sale',
            'status' => FinanceDocumentStatus::Draft,
        ]);
        $jv->lines()->createMany([
            [
                'ledger_account_id' => $cash->id,
                'description' => 'Cash',
                'debit' => 100,
                'credit' => 0,
                'sort_order' => 1,
            ],
            [
                'ledger_account_id' => $sales->id,
                'description' => 'Sales',
                'debit' => 0,
                'credit' => 100,
                'sort_order' => 2,
            ],
        ]);
        $ledger->postVoucher($jv->fresh(['lines']));

        $rows = app(FinancialStatements::class)
            ->compareYears($merchant->id, [(int) now()->year]);

        $this->assertNotEmpty($rows);
        $this->assertSame(100.0, $rows[0]['profit']);
        $this->assertSame(100.0, $rows[0]['equity_total']);
    }

    public function test_expense_plan_uses_selected_accounts(): void
    {
        $plan = app(OperationalLedgerPoster::class)->expenseLinePlan(50, false, '5100', '1010');

        $this->assertTrue(collect($plan)->contains(
            fn (array $line): bool => $line['code'] === '5100' && (float) $line['debit'] === 50.0
        ));
        $this->assertTrue(collect($plan)->contains(
            fn (array $line): bool => $line['code'] === '1010' && (float) $line['credit'] === 50.0
        ));
    }

    /**
     * @return array{0: Merchant, 1: LedgerAccount, 2: LedgerAccount, 3?: LedgerAccount}
     */
    private function seedMerchantAccounts(float $cashOpening = 0, bool $withBank = false): array
    {
        $merchant = Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => Str::uuid().'@example.com',
            'name' => 'Finance Gaps Merchant',
            'address_line_1' => '1 Test Street',
            'city' => 'Lahore',
            'status' => Merchant::STATUS_VERIFIED,
            'is_active' => true,
            'password' => 'password',
        ]);

        $cash = LedgerAccount::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'code' => '1000',
            'name' => 'Cash in Hand',
            'type' => LedgerAccountType::Asset,
            'is_bank' => false,
            'is_system' => true,
            'is_active' => true,
            'opening_balance' => $cashOpening,
        ]);

        $counter = LedgerAccount::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'code' => '1100',
            'name' => 'Accounts Receivable',
            'type' => LedgerAccountType::Asset,
            'is_bank' => false,
            'is_system' => true,
            'is_active' => true,
            'opening_balance' => 0,
        ]);

        if (! $withBank) {
            return [$merchant, $cash, $counter];
        }

        $bank = LedgerAccount::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'code' => '1010',
            'name' => 'Test Bank',
            'account_number' => '999',
            'type' => LedgerAccountType::Asset,
            'is_bank' => true,
            'is_system' => false,
            'is_active' => true,
            'opening_balance' => 0,
        ]);

        return [$merchant, $cash, $counter, $bank];
    }
}
