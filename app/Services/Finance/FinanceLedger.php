<?php

namespace App\Services\Finance;

use App\Enums\CashVoucherDirection;
use App\Enums\ChequeStatus;
use App\Enums\FinanceDocumentStatus;
use App\Enums\LedgerAccountType;
use App\Models\BankCheque;
use App\Models\BankDeposit;
use App\Models\BankReconciliation;
use App\Models\CashVoucher;
use App\Models\ChequeBook;
use App\Models\JournalVoucher;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\OnlineBankTransfer;
use App\Models\Purchase;
use App\Models\Vendor;
use App\Services\Inventory\CanteenStockImporter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceLedger
{
    public const CASH_ACCOUNT_CODE = '1000';

    public const BANK_ACCOUNT_CODE = '1010';

    public const INVENTORY_ACCOUNT_CODE = '1400';

    public const COGS_ACCOUNT_CODE = '5000';

    /**
     * @var list<array{code: string, name: string, type: LedgerAccountType, is_bank: bool}>
     */
    public const DEFAULT_ACCOUNTS = [
        ['code' => '1000', 'name' => 'Cash in Hand', 'type' => LedgerAccountType::Asset, 'is_bank' => false],
        ['code' => '1010', 'name' => 'UBL', 'type' => LedgerAccountType::Asset, 'is_bank' => true],
        ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => LedgerAccountType::Asset, 'is_bank' => false],
        ['code' => '1400', 'name' => 'Inventory', 'type' => LedgerAccountType::Asset, 'is_bank' => false],
        ['code' => '2000', 'name' => 'Accounts Payable', 'type' => LedgerAccountType::Liability, 'is_bank' => false],
        ['code' => '3000', 'name' => 'Owner Equity', 'type' => LedgerAccountType::Equity, 'is_bank' => false],
        ['code' => '4000', 'name' => 'Sales', 'type' => LedgerAccountType::Income, 'is_bank' => false],
        ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => LedgerAccountType::Expense, 'is_bank' => false],
        ['code' => '5100', 'name' => 'Operating Expenses', 'type' => LedgerAccountType::Expense, 'is_bank' => false],
        ['code' => '5200', 'name' => 'Payroll', 'type' => LedgerAccountType::Expense, 'is_bank' => false],
    ];

    /**
     * @param  list<string>  $existingCodes
     */
    public function suggestNextBankCode(array $existingCodes): string
    {
        $used = array_fill_keys($existingCodes, true);

        for ($code = 1010; $code <= 1990; $code += 10) {
            if (! isset($used[(string) $code])) {
                return (string) $code;
            }
        }

        $next = 2000;

        while (isset($used[(string) $next])) {
            $next++;
        }

        return (string) $next;
    }

    public function nextBankAccountCode(?string $merchantId = null): string
    {
        $codes = LedgerAccount::query()
            ->when($merchantId, fn ($query) => $query->where('merchant_id', $merchantId))
            ->pluck('code')
            ->all();

        return $this->suggestNextBankCode($codes);
    }

    /**
     * @param  list<string>  $existingCodes
     */
    public function suggestNextLedgerAccountCode(array $existingCodes): string
    {
        $used = array_fill_keys($existingCodes, true);

        for ($code = 6000; $code <= 9999; $code++) {
            if (! isset($used[(string) $code])) {
                return (string) $code;
            }
        }

        $next = 10000;

        while (isset($used[(string) $next])) {
            $next++;
        }

        return (string) $next;
    }

    public function nextLedgerAccountCode(?string $merchantId = null): string
    {
        $codes = LedgerAccount::query()
            ->when($merchantId, fn ($query) => $query->where('merchant_id', $merchantId))
            ->pluck('code')
            ->all();

        return $this->suggestNextLedgerAccountCode($codes);
    }

    public function createBankAccount(
        string $merchantId,
        string $name,
        float $openingBalance = 0,
        ?string $code = null,
        ?string $accountNumber = null,
    ): LedgerAccount {
        $name = trim($name);
        $accountNumber = trim((string) $accountNumber);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Enter the bank name.',
            ]);
        }

        if ($accountNumber === '') {
            throw ValidationException::withMessages([
                'account_number' => 'Enter the bank account number.',
            ]);
        }

        $code = $code ?: $this->nextBankAccountCode($merchantId);

        $exists = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('code', $code)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => "Ledger code {$code} is already in use.",
            ]);
        }

        $duplicateNumber = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('is_bank', true)
            ->where('account_number', $accountNumber)
            ->exists();

        if ($duplicateNumber) {
            throw ValidationException::withMessages([
                'account_number' => 'This account number is already saved for another bank.',
            ]);
        }

        return LedgerAccount::query()->create([
            'merchant_id' => $merchantId,
            'code' => $code,
            'name' => $name,
            'account_number' => $accountNumber,
            'type' => LedgerAccountType::Asset,
            'is_bank' => true,
            'is_system' => false,
            'is_active' => true,
            'opening_balance' => $openingBalance,
        ]);
    }

    public function provisionDefaultAccounts(Merchant $merchant): void
    {
        foreach (self::DEFAULT_ACCOUNTS as $account) {
            $ledgerAccount = LedgerAccount::query()->firstOrCreate(
                [
                    'merchant_id' => $merchant->id,
                    'code' => $account['code'],
                ],
                [
                    'name' => $account['name'],
                    'type' => $account['type'],
                    'is_bank' => $account['is_bank'],
                    'is_system' => true,
                    'is_active' => true,
                    'opening_balance' => 0,
                ]
            );

            if ($account['code'] === self::BANK_ACCOUNT_CODE && $ledgerAccount->is_system) {
                $ledgerAccount->forceFill([
                    'name' => $account['name'],
                    'is_bank' => true,
                    'type' => $account['type'],
                ])->save();
            }
        }
    }

    public function accountByCode(string $merchantId, string $code): LedgerAccount
    {
        $merchant = Merchant::query()->find($merchantId);

        if ($merchant) {
            $this->provisionDefaultAccounts($merchant);
        }

        $account = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('code', $code)
            ->first();

        if (! $account) {
            throw ValidationException::withMessages([
                'ledger' => "Ledger account {$code} is missing for this business.",
            ]);
        }

        return $account;
    }

    public function ensureVendorPayableAccount(Vendor $vendor): LedgerAccount
    {
        if (CanteenStockImporter::isOpeningStockVendor($vendor)) {
            throw ValidationException::withMessages([
                'ledger' => 'Opening stock is not posted to Chart of Accounts payables.',
            ]);
        }

        $vendor->loadMissing('merchant');

        if ($vendor->merchant) {
            $this->provisionDefaultAccounts($vendor->merchant);
        }

        $existing = LedgerAccount::query()
            ->where('merchant_id', $vendor->merchant_id)
            ->where('vendor_id', $vendor->id)
            ->first();

        $name = trim((string) $vendor->name);
        if ($name === '') {
            $name = 'Vendor';
        }

        if ($existing) {
            if ($existing->name !== $name) {
                $existing->forceFill(['name' => $name])->save();
            }

            return $existing;
        }

        return LedgerAccount::query()->create([
            'merchant_id' => $vendor->merchant_id,
            'vendor_id' => $vendor->id,
            'code' => $this->nextVendorPayableCode($vendor->merchant_id),
            'name' => $name,
            'type' => LedgerAccountType::Liability,
            'is_bank' => false,
            'is_system' => false,
            'is_active' => true,
            'opening_balance' => 0,
        ]);
    }

    public function nextVendorPayableCode(string $merchantId): string
    {
        $used = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->pluck('code')
            ->flip()
            ->all();

        for ($code = 2001; $code <= 2999; $code++) {
            if (! isset($used[(string) $code])) {
                return (string) $code;
            }
        }

        return $this->nextLedgerAccountCode($merchantId);
    }

    /**
     * Create missing vendor payable accounts and re-post purchases so balances move off Accounts Payable.
     */
    public function backfillVendorPayableAccounts(string $merchantId): void
    {
        $vendorIds = Purchase::query()
            ->where('merchant_id', $merchantId)
            ->whereNotNull('vendor_id')
            ->where('purchase_no', '!=', CanteenStockImporter::OPENING_PURCHASE_NO)
            ->distinct()
            ->pluck('vendor_id');

        if ($vendorIds->isEmpty()) {
            return;
        }

        $existingVendorIds = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->whereIn('vendor_id', $vendorIds)
            ->pluck('vendor_id');

        $missingIds = $vendorIds->diff($existingVendorIds);

        if ($missingIds->isEmpty()) {
            return;
        }

        $vendors = Vendor::query()
            ->withTrashed()
            ->where('merchant_id', $merchantId)
            ->whereIn('id', $missingIds->all())
            ->get()
            ->reject(fn (Vendor $vendor): bool => CanteenStockImporter::isOpeningStockVendor($vendor));

        $poster = app(OperationalLedgerPoster::class);

        foreach ($vendors as $vendor) {
            $this->ensureVendorPayableAccount($vendor);

            Purchase::query()
                ->where('merchant_id', $merchantId)
                ->where('vendor_id', $vendor->id)
                ->where('purchase_no', '!=', CanteenStockImporter::OPENING_PURCHASE_NO)
                ->get()
                ->each(fn (Purchase $purchase) => $poster->syncPurchase($purchase));
        }
    }

    /**
     * Remove opening-stock journal vouchers and any vendor payable ledger account created for them.
     */
    public function purgeOpeningStockLedger(string $merchantId): void
    {
        Purchase::withTrashed()
            ->where('merchant_id', $merchantId)
            ->where('purchase_no', CanteenStockImporter::OPENING_PURCHASE_NO)
            ->get()
            ->each(fn (Purchase $purchase) => $this->removeForSource($purchase));

        $vendorIds = Vendor::withTrashed()
            ->where('merchant_id', $merchantId)
            ->where(function ($query): void {
                $query
                    ->where('name', CanteenStockImporter::OPENING_VENDOR_NAME)
                    ->orWhere('reference', CanteenStockImporter::OPENING_VENDOR_REFERENCE)
                    ->orWhere('email', CanteenStockImporter::OPENING_VENDOR_EMAIL);
            })
            ->pluck('id');

        if ($vendorIds->isEmpty()) {
            return;
        }

        LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->whereIn('vendor_id', $vendorIds)
            ->get()
            ->each(function (LedgerAccount $account): void {
                $account->journalLines()->delete();
                $account->delete();
            });
    }

    public function syncOpeningCash(Merchant $merchant): void
    {
        $this->provisionDefaultAccounts($merchant);

        LedgerAccount::query()
            ->where('merchant_id', $merchant->id)
            ->where('code', '1000')
            ->update(['opening_balance' => (float) ($merchant->cash_in_hand ?? 0)]);

        LedgerAccount::query()
            ->where('merchant_id', $merchant->id)
            ->where('code', '1010')
            ->update(['opening_balance' => (float) ($merchant->cash_in_bank ?? 0)]);
    }

    /**
     * @param  list<array{ledger_account_id?: mixed, debit?: mixed, credit?: mixed, description?: mixed}>  $lines
     */
    public function postOrReplaceForSource(
        Model $source,
        string $merchantId,
        mixed $voucherDate,
        string $narration,
        array $lines,
        ?string $createdBy = null,
        ?string $vendorId = null,
    ): ?JournalVoucher {
        $lines = array_values(array_filter(
            $lines,
            fn (array $line): bool => round((float) ($line['debit'] ?? 0), 2) > 0
                || round((float) ($line['credit'] ?? 0), 2) > 0
        ));

        if ($lines === []) {
            $this->removeForSource($source);

            return null;
        }

        $this->assertBalanced($lines);

        return DB::transaction(function () use ($source, $merchantId, $voucherDate, $narration, $lines, $createdBy, $vendorId): JournalVoucher {
            $voucher = JournalVoucher::withTrashed()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->first();

            if ($voucher) {
                if ($voucher->trashed()) {
                    $voucher->restore();
                }

                $voucher->forceFill([
                    'merchant_id' => $merchantId,
                    'voucher_date' => $voucherDate,
                    'narration' => $narration,
                    'created_by' => $createdBy ?? $voucher->created_by,
                    'vendor_id' => $vendorId,
                ])->save();

                $voucher->lines()->delete();
            } else {
                $voucher = JournalVoucher::query()->create([
                    'merchant_id' => $merchantId,
                    'voucher_no' => $this->nextVoucherNo($merchantId, $voucherDate),
                    'voucher_date' => $voucherDate,
                    'narration' => $narration,
                    'status' => FinanceDocumentStatus::Draft,
                    'created_by' => $createdBy,
                    'vendor_id' => $vendorId,
                    'source_type' => $source->getMorphClass(),
                    'source_id' => $source->getKey(),
                ]);
            }

            foreach (array_values($lines) as $index => $line) {
                $voucher->lines()->create([
                    'ledger_account_id' => $line['ledger_account_id'],
                    'description' => $line['description'] ?? null,
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'sort_order' => $index + 1,
                ]);
            }

            return $this->postVoucher($voucher->fresh(['lines']));
        });
    }

    public function removeForSource(Model $source): void
    {
        JournalVoucher::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->get()
            ->each(function (JournalVoucher $voucher): void {
                $voucher->lines()->delete();
                $voucher->delete();
            });
    }

    public function nextVoucherNo(string $merchantId, mixed $date = null): string
    {
        return $this->nextDocumentNo($merchantId, 'JV', JournalVoucher::class, 'voucher_no', $date);
    }

    public function nextDepositNo(string $merchantId, mixed $date = null): string
    {
        return $this->nextDocumentNo($merchantId, 'BD', BankDeposit::class, 'deposit_no', $date);
    }

    public function nextCashVoucherNo(string $merchantId, CashVoucherDirection $direction, mixed $date = null): string
    {
        $prefix = $direction === CashVoucherDirection::Receiving ? 'CRV' : 'CPV';

        return $this->nextDocumentNo($merchantId, $prefix, CashVoucher::class, 'voucher_no', $date);
    }

    public function nextOnlineTransferNo(string $merchantId, mixed $date = null): string
    {
        return $this->nextDocumentNo($merchantId, 'OBT', OnlineBankTransfer::class, 'transfer_no', $date);
    }

    public function postCashVoucher(CashVoucher $voucher): CashVoucher
    {
        if ($voucher->isPosted()) {
            return $voucher->fresh(['journalVoucher', 'cashAccount', 'counterAccount']) ?? $voucher;
        }

        if ((float) $voucher->amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be greater than zero.',
            ]);
        }

        if ($voucher->cash_account_id === $voucher->counter_account_id) {
            throw ValidationException::withMessages([
                'counter_account_id' => 'Cash/bank and counter account must be different.',
            ]);
        }

        $voucher->loadMissing(['cashAccount', 'counterAccount']);

        return DB::transaction(function () use ($voucher): CashVoucher {
            $amount = round((float) $voucher->amount, 2);
            $isReceiving = $voucher->direction === CashVoucherDirection::Receiving;

            $lines = $isReceiving
                ? [
                    [
                        'ledger_account_id' => $voucher->cash_account_id,
                        'description' => 'Cash received',
                        'debit' => $amount,
                        'credit' => 0,
                        'sort_order' => 1,
                    ],
                    [
                        'ledger_account_id' => $voucher->counter_account_id,
                        'description' => 'Received from',
                        'debit' => 0,
                        'credit' => $amount,
                        'sort_order' => 2,
                    ],
                ]
                : [
                    [
                        'ledger_account_id' => $voucher->counter_account_id,
                        'description' => 'Paid to',
                        'debit' => $amount,
                        'credit' => 0,
                        'sort_order' => 1,
                    ],
                    [
                        'ledger_account_id' => $voucher->cash_account_id,
                        'description' => 'Cash paid',
                        'debit' => 0,
                        'credit' => $amount,
                        'sort_order' => 2,
                    ],
                ];

            $jv = JournalVoucher::query()->create([
                'merchant_id' => $voucher->merchant_id,
                'voucher_no' => $this->nextVoucherNo($voucher->merchant_id, $voucher->voucher_date),
                'voucher_date' => $voucher->voucher_date,
                'narration' => $voucher->direction->label().' '.$voucher->voucher_no,
                'status' => FinanceDocumentStatus::Draft,
                'created_by' => $voucher->created_by,
            ]);

            $jv->lines()->createMany($lines);
            $this->postVoucher($jv->fresh(['lines']));

            $voucher->forceFill([
                'status' => FinanceDocumentStatus::Posted,
                'journal_voucher_id' => $jv->id,
            ])->save();

            return $voucher->fresh(['journalVoucher', 'cashAccount', 'counterAccount']) ?? $voucher;
        });
    }

    public function postOnlineBankTransfer(OnlineBankTransfer $transfer): OnlineBankTransfer
    {
        if ($transfer->isPosted()) {
            return $transfer->fresh(['journalVoucher', 'fromAccount', 'toAccount']) ?? $transfer;
        }

        if ((float) $transfer->amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be greater than zero.',
            ]);
        }

        if ($transfer->from_account_id === $transfer->to_account_id) {
            throw ValidationException::withMessages([
                'to_account_id' => 'From and to accounts must be different.',
            ]);
        }

        return DB::transaction(function () use ($transfer): OnlineBankTransfer {
            $amount = round((float) $transfer->amount, 2);

            $jv = JournalVoucher::query()->create([
                'merchant_id' => $transfer->merchant_id,
                'voucher_no' => $this->nextVoucherNo($transfer->merchant_id, $transfer->transfer_date),
                'voucher_date' => $transfer->transfer_date,
                'narration' => 'Online transfer '.$transfer->transfer_no.(filled($transfer->reference_no) ? ' ref '.$transfer->reference_no : ''),
                'status' => FinanceDocumentStatus::Draft,
                'created_by' => $transfer->created_by,
            ]);

            $jv->lines()->createMany([
                [
                    'ledger_account_id' => $transfer->to_account_id,
                    'description' => 'Online transfer in',
                    'debit' => $amount,
                    'credit' => 0,
                    'sort_order' => 1,
                ],
                [
                    'ledger_account_id' => $transfer->from_account_id,
                    'description' => 'Online transfer out',
                    'debit' => 0,
                    'credit' => $amount,
                    'sort_order' => 2,
                ],
            ]);

            $this->postVoucher($jv->fresh(['lines']));

            $transfer->forceFill([
                'status' => FinanceDocumentStatus::Posted,
                'journal_voucher_id' => $jv->id,
            ])->save();

            return $transfer->fresh(['journalVoucher', 'fromAccount', 'toAccount']) ?? $transfer;
        });
    }

    /**
     * @param  list<array{ledger_account_id?: mixed, debit?: mixed, credit?: mixed}>  $lines
     */
    public function assertBalanced(array $lines): void
    {
        $debit = 0.0;
        $credit = 0.0;
        $validLines = 0;

        foreach ($lines as $line) {
            $lineDebit = round((float) ($line['debit'] ?? 0), 2);
            $lineCredit = round((float) ($line['credit'] ?? 0), 2);

            if ($lineDebit <= 0 && $lineCredit <= 0) {
                throw ValidationException::withMessages([
                    'lines' => 'Each journal line must have a debit or a credit amount.',
                ]);
            }

            if ($lineDebit > 0 && $lineCredit > 0) {
                throw ValidationException::withMessages([
                    'lines' => 'A journal line cannot have both debit and credit.',
                ]);
            }

            if (blank($line['ledger_account_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'lines' => 'Each journal line must have an account.',
                ]);
            }

            $debit += $lineDebit;
            $credit += $lineCredit;
            $validLines++;
        }

        if ($validLines < 2) {
            throw ValidationException::withMessages([
                'lines' => 'A journal voucher needs at least two lines.',
            ]);
        }

        if (round($debit, 2) !== round($credit, 2)) {
            throw ValidationException::withMessages([
                'lines' => 'Total debit must equal total credit.',
            ]);
        }
    }

    public function postVoucher(JournalVoucher $voucher): JournalVoucher
    {
        $voucher->loadMissing('lines');
        $this->assertBalanced($voucher->lines->map(fn ($line) => $line->only(['ledger_account_id', 'debit', 'credit']))->all());

        $voucher->forceFill([
            'status' => FinanceDocumentStatus::Posted,
            'posted_at' => now(),
        ])->save();

        return $voucher;
    }

    public function postBankDeposit(BankDeposit $deposit): BankDeposit
    {
        if ($deposit->isPosted()) {
            return $deposit;
        }

        if (blank($deposit->reference_no)) {
            throw ValidationException::withMessages([
                'reference_no' => 'Enter the bank deposit slip number before posting.',
            ]);
        }

        if ((float) $deposit->amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Deposit amount must be greater than zero.',
            ]);
        }

        if ($deposit->bank_account_id === $deposit->source_account_id) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'Bank account and source account must be different.',
            ]);
        }

        $deposit->loadMissing('bankAccount');

        if (! $deposit->bankAccount?->is_bank) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'Deposits must be posted to a bank ledger account.',
            ]);
        }

        return DB::transaction(function () use ($deposit): BankDeposit {
            $voucher = JournalVoucher::query()->create([
                'merchant_id' => $deposit->merchant_id,
                'voucher_no' => $this->nextVoucherNo($deposit->merchant_id, $deposit->deposit_date),
                'voucher_date' => $deposit->deposit_date,
                'narration' => ($deposit->bankAccount?->name ?? 'Bank').' deposit '.$deposit->deposit_no.(filled($deposit->reference_no) ? ' slip '.$deposit->reference_no : ''),
                'status' => FinanceDocumentStatus::Draft,
                'created_by' => $deposit->created_by,
            ]);

            $voucher->lines()->createMany([
                [
                    'ledger_account_id' => $deposit->bank_account_id,
                    'description' => ($deposit->bankAccount?->name ?? 'Bank').' deposit'.(filled($deposit->reference_no) ? ' slip '.$deposit->reference_no : ''),
                    'debit' => $deposit->amount,
                    'credit' => 0,
                    'sort_order' => 1,
                ],
                [
                    'ledger_account_id' => $deposit->source_account_id,
                    'description' => 'Transferred to bank',
                    'debit' => 0,
                    'credit' => $deposit->amount,
                    'sort_order' => 2,
                ],
            ]);

            $this->postVoucher($voucher->fresh(['lines']));

            $deposit->forceFill([
                'status' => FinanceDocumentStatus::Posted,
                'journal_voucher_id' => $voucher->id,
            ])->save();

            return $deposit->fresh(['journalVoucher', 'bankAccount', 'sourceAccount']);
        });
    }

    public function nextReconNo(string $merchantId): string
    {
        return $this->nextDocumentNo($merchantId, 'BR', BankReconciliation::class, 'recon_no');
    }

    public function allocateChequeNumber(ChequeBook $book): string
    {
        if (! $book->is_active) {
            throw ValidationException::withMessages([
                'cheque_book_id' => 'This cheque book is inactive.',
            ]);
        }

        if (! $book->hasAvailableLeaves()) {
            throw ValidationException::withMessages([
                'cheque_book_id' => 'This cheque book has no remaining leaves.',
            ]);
        }

        $number = (string) $book->next_number;
        $book->forceFill(['next_number' => $book->next_number + 1])->save();

        return $number;
    }

    public function clearBankCheque(BankCheque $cheque): BankCheque
    {
        if ($cheque->isCleared()) {
            return $cheque->fresh(['journalVoucher', 'bankAccount', 'counterAccount']) ?? $cheque;
        }

        if (! $cheque->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Only pending cheques can be cleared.',
            ]);
        }

        if ((float) $cheque->amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Cheque amount must be greater than zero.',
            ]);
        }

        $cheque->loadMissing(['bankAccount', 'counterAccount']);

        if (! $cheque->bankAccount?->is_bank) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'Cheques must clear against a bank ledger account.',
            ]);
        }

        if ($cheque->bank_account_id === $cheque->counter_account_id) {
            throw ValidationException::withMessages([
                'counter_account_id' => 'Bank and counter account must be different.',
            ]);
        }

        return DB::transaction(function () use ($cheque): BankCheque {
            $amount = round((float) $cheque->amount, 2);
            $bankId = $cheque->bank_account_id;
            $counterId = $cheque->counter_account_id;
            $bankName = $cheque->bankAccount?->name ?? 'Bank';
            $chequeLabel = 'Cheque '.$cheque->cheque_number;

            if ($cheque->isOutgoing()) {
                $lines = [
                    [
                        'ledger_account_id' => $counterId,
                        'description' => $chequeLabel.' to '.($cheque->payee_name ?: 'payee'),
                        'debit' => $amount,
                        'credit' => 0,
                        'sort_order' => 1,
                    ],
                    [
                        'ledger_account_id' => $bankId,
                        'description' => $chequeLabel.' cleared',
                        'debit' => 0,
                        'credit' => $amount,
                        'sort_order' => 2,
                    ],
                ];
            } else {
                $lines = [
                    [
                        'ledger_account_id' => $bankId,
                        'description' => $chequeLabel.' cleared',
                        'debit' => $amount,
                        'credit' => 0,
                        'sort_order' => 1,
                    ],
                    [
                        'ledger_account_id' => $counterId,
                        'description' => $chequeLabel.' from '.($cheque->payer_name ?: 'payer'),
                        'debit' => 0,
                        'credit' => $amount,
                        'sort_order' => 2,
                    ],
                ];
            }

            $voucher = JournalVoucher::query()->create([
                'merchant_id' => $cheque->merchant_id,
                'voucher_no' => $this->nextVoucherNo($cheque->merchant_id, $cheque->cheque_date),
                'voucher_date' => $cheque->cheque_date,
                'narration' => $bankName.' '.$cheque->direction->label().' '.$chequeLabel,
                'status' => FinanceDocumentStatus::Draft,
                'created_by' => $cheque->created_by,
            ]);

            $voucher->lines()->createMany($lines);
            $this->postVoucher($voucher->fresh(['lines']));

            $cheque->forceFill([
                'status' => ChequeStatus::Cleared,
                'cleared_at' => now(),
                'journal_voucher_id' => $voucher->id,
            ])->save();

            return $cheque->fresh(['journalVoucher', 'bankAccount', 'counterAccount']) ?? $cheque;
        });
    }

    public function bounceBankCheque(BankCheque $cheque): BankCheque
    {
        if ($cheque->status === ChequeStatus::Bounced) {
            return $cheque->fresh(['journalVoucher', 'reversalVoucher']) ?? $cheque;
        }

        if ($cheque->status === ChequeStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => 'Cancelled cheques cannot be bounced.',
            ]);
        }

        return DB::transaction(function () use ($cheque): BankCheque {
            $reversalId = null;

            if ($cheque->isCleared() && $cheque->journal_voucher_id) {
                $original = JournalVoucher::query()->with('lines')->find($cheque->journal_voucher_id);

                if ($original) {
                    $reversal = JournalVoucher::query()->create([
                        'merchant_id' => $cheque->merchant_id,
                        'voucher_no' => $this->nextVoucherNo($cheque->merchant_id, now()),
                        'voucher_date' => now()->toDateString(),
                        'narration' => 'Reversal: bounced cheque '.$cheque->cheque_number,
                        'status' => FinanceDocumentStatus::Draft,
                        'created_by' => $cheque->created_by,
                    ]);

                    $reversal->lines()->createMany(
                        $original->lines->map(fn ($line, int $index): array => [
                            'ledger_account_id' => $line->ledger_account_id,
                            'description' => 'Reversal: '.($line->description ?: 'cheque '.$cheque->cheque_number),
                            'debit' => (float) $line->credit,
                            'credit' => (float) $line->debit,
                            'sort_order' => $index + 1,
                        ])->all()
                    );

                    $this->postVoucher($reversal->fresh(['lines']));
                    $reversalId = $reversal->id;
                }
            }

            $cheque->forceFill([
                'status' => ChequeStatus::Bounced,
                'bounced_at' => now(),
                'reversal_voucher_id' => $reversalId,
            ])->save();

            return $cheque->fresh(['journalVoucher', 'reversalVoucher', 'bankAccount']) ?? $cheque;
        });
    }

    public function cancelBankCheque(BankCheque $cheque): BankCheque
    {
        if (! $cheque->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Only pending cheques can be cancelled.',
            ]);
        }

        $cheque->forceFill([
            'status' => ChequeStatus::Cancelled,
        ])->save();

        return $cheque->fresh() ?? $cheque;
    }

    private function nextDocumentNo(string $merchantId, string $prefix, string $model, string $column, mixed $date = null): string
    {
        $datePart = filled($date)
            ? Carbon::parse($date)->format('Ymd')
            : now()->format('Ymd');
        $base = "{$prefix}-{$datePart}-";

        $last = $model::query()
            ->withTrashed()
            ->where('merchant_id', $merchantId)
            ->where($column, 'like', $base.'%')
            ->orderByDesc($column)
            ->value($column);

        $sequence = 1;

        if (is_string($last) && preg_match('/-(\d+)$/', $last, $matches) === 1) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return $base.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
