<?php

namespace Tests\Feature;

use App\Enums\BankReconciliationStatus;
use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Enums\LedgerAccountType;
use App\Models\BankCheque;
use App\Models\BankReconciliation;
use App\Models\ChequeBook;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Services\Finance\BankReconciliationService;
use App\Services\Finance\FinanceLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BankingChequesReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_outgoing_clear_reduces_bank_balance_and_bounce_reverses(): void
    {
        [$merchant, $bank, $counter] = $this->seedMerchantWithBank(opening: 1000);

        $book = ChequeBook::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'bank_account_id' => $bank->id,
            'book_no' => 'CB-1',
            'start_number' => 100,
            'end_number' => 110,
            'next_number' => 100,
            'is_active' => true,
        ]);

        $ledger = app(FinanceLedger::class);
        $number = $ledger->allocateChequeNumber($book->fresh());
        $this->assertSame('100', $number);
        $this->assertSame(101, $book->fresh()->next_number);

        $cheque = BankCheque::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'direction' => ChequeDirection::Outgoing,
            'bank_account_id' => $bank->id,
            'cheque_book_id' => $book->id,
            'cheque_number' => $number,
            'cheque_date' => now()->toDateString(),
            'amount' => 250,
            'payee_name' => 'Vendor A',
            'counter_account_id' => $counter->id,
            'status' => ChequeStatus::Pending,
        ]);

        $ledger->clearBankCheque($cheque->fresh(['bankAccount', 'counterAccount']));
        $this->assertSame('750.00', $bank->fresh()->postedBalance());
        $this->assertTrue($cheque->fresh()->isCleared());

        $ledger->bounceBankCheque($cheque->fresh(['bankAccount', 'counterAccount', 'journalVoucher']));
        $this->assertSame('1000.00', $bank->fresh()->postedBalance());
        $this->assertSame(ChequeStatus::Bounced, $cheque->fresh()->status);
    }

    public function test_incoming_clear_increases_bank_balance(): void
    {
        [$merchant, $bank, $counter] = $this->seedMerchantWithBank(opening: 500);

        $cheque = BankCheque::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'direction' => ChequeDirection::Incoming,
            'bank_account_id' => $bank->id,
            'cheque_number' => 'IN-55',
            'cheque_date' => now()->toDateString(),
            'amount' => 125.50,
            'payer_name' => 'Customer B',
            'counter_account_id' => $counter->id,
            'status' => ChequeStatus::Pending,
        ]);

        app(FinanceLedger::class)->clearBankCheque($cheque->fresh(['bankAccount', 'counterAccount']));

        $this->assertSame('625.50', $bank->fresh()->postedBalance());
    }

    public function test_cheque_book_rejects_overflow(): void
    {
        [$merchant, $bank] = $this->seedMerchantWithBank(opening: 0);

        $book = ChequeBook::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'bank_account_id' => $bank->id,
            'book_no' => 'CB-FULL',
            'start_number' => 1,
            'end_number' => 1,
            'next_number' => 2,
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        app(FinanceLedger::class)->allocateChequeNumber($book);
    }

    public function test_reconciliation_completes_only_when_difference_is_zero(): void
    {
        [$merchant, $bank, $counter] = $this->seedMerchantWithBank(opening: 1000);
        $ledger = app(FinanceLedger::class);

        $cheque = BankCheque::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'direction' => ChequeDirection::Outgoing,
            'bank_account_id' => $bank->id,
            'cheque_number' => '200',
            'cheque_date' => now()->toDateString(),
            'amount' => 100,
            'payee_name' => 'Payee',
            'counter_account_id' => $counter->id,
            'status' => ChequeStatus::Pending,
        ]);
        $ledger->clearBankCheque($cheque->fresh(['bankAccount', 'counterAccount']));

        $service = app(BankReconciliationService::class);
        $recon = BankReconciliation::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'bank_account_id' => $bank->id,
            'recon_no' => $service->nextReconNo($merchant->id),
            'period_from' => now()->subDay()->toDateString(),
            'period_to' => now()->addDay()->toDateString(),
            'statement_opening' => 1000,
            'statement_closing' => 900,
            'status' => BankReconciliationStatus::InProgress,
        ]);

        $service->seedLines($recon);
        $item = $recon->items()->first();
        $this->assertNotNull($item);
        $service->toggleMatch($item, true);

        $completed = $service->complete($recon->fresh(['items.journalVoucherLine']));
        $this->assertTrue($completed->isCompleted());

        $bad = BankReconciliation::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'bank_account_id' => $bank->id,
            'recon_no' => $service->nextReconNo($merchant->id),
            'period_from' => now()->subDay()->toDateString(),
            'period_to' => now()->addDay()->toDateString(),
            'statement_opening' => 1000,
            'statement_closing' => 950,
            'status' => BankReconciliationStatus::InProgress,
        ]);
        $service->seedLines($bad);
        foreach ($bad->items as $lineItem) {
            $service->toggleMatch($lineItem, true);
        }

        $this->expectException(ValidationException::class);
        $service->complete($bad->fresh(['items.journalVoucherLine']));
    }

    /**
     * @return array{0: Merchant, 1: LedgerAccount, 2?: LedgerAccount}
     */
    private function seedMerchantWithBank(float $opening): array
    {
        $merchant = Merchant::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => Str::uuid().'@example.com',
            'name' => 'Bank Test Merchant',
            'address_line_1' => '1 Test Street',
            'city' => 'Lahore',
            'status' => Merchant::STATUS_VERIFIED,
            'is_active' => true,
            'password' => 'password',
        ]);

        $bank = LedgerAccount::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'code' => '1010',
            'name' => 'Test Bank',
            'account_number' => '123456',
            'type' => LedgerAccountType::Asset,
            'is_bank' => true,
            'is_system' => false,
            'is_active' => true,
            'opening_balance' => $opening,
        ]);

        $counter = LedgerAccount::query()->create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'code' => '5100',
            'name' => 'Operating Expenses',
            'type' => LedgerAccountType::Expense,
            'is_bank' => false,
            'is_system' => true,
            'is_active' => true,
            'opening_balance' => 0,
        ]);

        return [$merchant, $bank, $counter];
    }
}
