<?php

namespace App\Services\Finance;

use App\Enums\FinanceDocumentStatus;
use App\Enums\LedgerAccountType;
use App\Models\BankDeposit;
use App\Models\JournalVoucher;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceLedger
{
    public const CASH_ACCOUNT_CODE = '1000';

    public const BANK_ACCOUNT_CODE = '1010';

    /**
     * @var list<array{code: string, name: string, type: LedgerAccountType, is_bank: bool}>
     */
    public const DEFAULT_ACCOUNTS = [
        ['code' => '1000', 'name' => 'Cash in Hand', 'type' => LedgerAccountType::Asset, 'is_bank' => false],
        ['code' => '1010', 'name' => 'UBL', 'type' => LedgerAccountType::Asset, 'is_bank' => true],
        ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => LedgerAccountType::Asset, 'is_bank' => false],
        ['code' => '2000', 'name' => 'Accounts Payable', 'type' => LedgerAccountType::Liability, 'is_bank' => false],
        ['code' => '3000', 'name' => 'Owner Equity', 'type' => LedgerAccountType::Equity, 'is_bank' => false],
        ['code' => '4000', 'name' => 'Sales', 'type' => LedgerAccountType::Income, 'is_bank' => false],
        ['code' => '5000', 'name' => 'Purchases', 'type' => LedgerAccountType::Expense, 'is_bank' => false],
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

        return DB::transaction(function () use ($source, $merchantId, $voucherDate, $narration, $lines, $createdBy): JournalVoucher {
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
                ])->save();

                $voucher->lines()->delete();
            } else {
                $voucher = JournalVoucher::query()->create([
                    'merchant_id' => $merchantId,
                    'voucher_no' => $this->nextVoucherNo($merchantId),
                    'voucher_date' => $voucherDate,
                    'narration' => $narration,
                    'status' => FinanceDocumentStatus::Draft,
                    'created_by' => $createdBy,
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

    public function nextVoucherNo(string $merchantId): string
    {
        return $this->nextDocumentNo($merchantId, 'JV', JournalVoucher::class, 'voucher_no');
    }

    public function nextDepositNo(string $merchantId): string
    {
        return $this->nextDocumentNo($merchantId, 'BD', BankDeposit::class, 'deposit_no');
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
                'voucher_no' => $this->nextVoucherNo($deposit->merchant_id),
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

    private function nextDocumentNo(string $merchantId, string $prefix, string $model, string $column): string
    {
        $date = now()->format('Ymd');
        $base = "{$prefix}-{$date}-";

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
