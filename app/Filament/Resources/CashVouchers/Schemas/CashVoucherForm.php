<?php

namespace App\Filament\Resources\CashVouchers\Schemas;

use App\Enums\CashVoucherDirection;
use App\Models\LedgerAccount;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class CashVoucherForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('merchant_id')
                ->default(fn () => FinanceAccess::merchantId())
                ->required(),

            Select::make('direction')
                ->label('Direction')
                ->options(CashVoucherDirection::options())
                ->default(CashVoucherDirection::Receiving->value)
                ->required()
                ->live()
                ->native(false)
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    if (! $state) {
                        return;
                    }

                    $merchantId = FinanceAccess::merchantId();

                    if (! $merchantId) {
                        return;
                    }

                    $set(
                        'voucher_no',
                        app(FinanceLedger::class)->nextCashVoucherNo(
                            (string) $merchantId,
                            CashVoucherDirection::from($state),
                        ),
                    );
                }),

            TextInput::make('voucher_no')
                ->label('Voucher No')
                ->default(fn (): string => app(FinanceLedger::class)->nextCashVoucherNo(
                    (string) FinanceAccess::merchantId(),
                    CashVoucherDirection::Receiving,
                ))
                ->required()
                ->maxLength(50),

            DatePicker::make('voucher_date')
                ->label('Date')
                ->default(now())
                ->required()
                ->displayFormat('d/m/Y'),

            Select::make('cash_account_id')
                ->label('Cash / bank account')
                ->options(fn (): array => self::cashOrBankOptions())
                ->default(fn (): ?string => self::defaultCashOrBankAccountId())
                ->searchable()
                ->required()
                ->native(false),

            Select::make('counter_account_id')
                ->label('Counter account')
                ->options(fn (): array => self::activeLedgerOptions())
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
                ->label('Reference No')
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
    private static function cashOrBankOptions(): array
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return [];
        }

        return LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('is_bank', true)
                    ->orWhere('code', FinanceLedger::CASH_ACCOUNT_CODE);
            })
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (LedgerAccount $account): array => [
                $account->id => $account->is_bank ? $account->bankLabel() : $account->name,
            ])
            ->all();
    }

    private static function defaultCashOrBankAccountId(): ?string
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return null;
        }

        return LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->where('code', FinanceLedger::CASH_ACCOUNT_CODE)
            ->orderBy('code')
            ->value('id');
    }

    /**
     * @return array<string, string>
     */
    private static function activeLedgerOptions(): array
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return [];
        }

        return LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (LedgerAccount $account): array => [
                $account->id => $account->code.' — '.$account->name,
            ])
            ->all();
    }
}
