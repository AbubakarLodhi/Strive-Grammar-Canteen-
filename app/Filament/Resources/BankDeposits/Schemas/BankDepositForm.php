<?php

namespace App\Filament\Resources\BankDeposits\Schemas;

use App\Filament\Resources\BankAccounts\Schemas\BankAccountForm;
use App\Models\LedgerAccount;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BankDepositForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('merchant_id')
                ->default(fn () => FinanceAccess::merchantId())
                ->required(),

            TextInput::make('deposit_no')
                ->label('Deposit Slip No')
                ->default(fn (): string => app(FinanceLedger::class)->nextDepositNo((string) FinanceAccess::merchantId()))
                ->required()
                ->maxLength(50),

            DatePicker::make('deposit_date')
                ->label('Date')
                ->default(now())
                ->required()
                ->displayFormat('d/m/Y'),

            Select::make('bank_account_id')
                ->label('Bank account')
                ->options(fn () => self::accountOptions(banksOnly: true))
                ->default(fn () => self::defaultAccountId(banksOnly: true))
                ->searchable()
                ->required()
                ->native(false)
                ->createOptionModalHeading('Add bank account')
                ->createOptionForm(BankAccountForm::components(forQuickCreate: true))
                ->createOptionUsing(function (array $data): string {
                    $account = app(FinanceLedger::class)->createBankAccount(
                        (string) FinanceAccess::merchantId(),
                        (string) ($data['name'] ?? ''),
                        (float) ($data['opening_balance'] ?? 0),
                        null,
                        (string) ($data['account_number'] ?? ''),
                    );

                    return $account->id;
                }),

            Select::make('source_account_id')
                ->label('Source Account')
                ->helperText('Usually Cash in Hand.')
                ->options(fn () => self::accountOptions(banksOnly: false))
                ->default(fn () => self::defaultAccountId(banksOnly: false))
                ->searchable()
                ->required()
                ->native(false),

            TextInput::make('amount')
                ->label('Amount')
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->step(0.01),

            TextInput::make('reference_no')
                ->label('Bank slip number')
                ->helperText('Print the deposit slip, deposit at the bank, then enter the bank slip number here before posting.')
                ->maxLength(100),

            Textarea::make('notes')
                ->label('Notes')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function accountOptions(bool $banksOnly): array
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return [];
        }

        $query = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->orderBy('name');

        if ($banksOnly) {
            $query->where('is_bank', true);
        }

        return $query
            ->get()
            ->mapWithKeys(fn (LedgerAccount $account): array => [
                $account->id => $banksOnly ? $account->bankLabel() : $account->name,
            ])
            ->all();
    }

    private static function defaultAccountId(bool $banksOnly): ?string
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return null;
        }

        $query = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('is_active', true);

        if ($banksOnly) {
            $query->where('is_bank', true)->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [FinanceLedger::BANK_ACCOUNT_CODE]);
        } else {
            $query->where('code', FinanceLedger::CASH_ACCOUNT_CODE);
        }

        return $query->orderBy('code')->value('id');
    }
}
