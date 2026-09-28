<?php

namespace App\Filament\Resources\BankCheques\Schemas;

use App\Enums\ChequeDirection;
use App\Models\ChequeBook;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Vendor;
use App\Support\FinanceAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class BankChequeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('merchant_id')
                ->default(fn () => FinanceAccess::merchantId())
                ->required(),

            Select::make('direction')
                ->label('Direction')
                ->options(ChequeDirection::options())
                ->required()
                ->live()
                ->native(false)
                ->afterStateUpdated(function (Set $set): void {
                    $set('cheque_book_id', null);
                    $set('cheque_number', null);
                    $set('payee_name', null);
                    $set('payer_name', null);
                }),

            Select::make('bank_account_id')
                ->label('Bank account')
                ->options(fn () => self::bankOptions())
                ->searchable()
                ->required()
                ->live()
                ->native(false)
                ->afterStateUpdated(fn (Set $set) => $set('cheque_book_id', null)),

            Select::make('cheque_book_id')
                ->label('Cheque book')
                ->options(fn (Get $get): array => self::chequeBookOptions($get('bank_account_id')))
                ->visible(fn (Get $get): bool => $get('direction') === ChequeDirection::Outgoing->value)
                ->required(fn (Get $get): bool => $get('direction') === ChequeDirection::Outgoing->value)
                ->searchable()
                ->live()
                ->native(false)
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    if (! $state) {
                        return;
                    }

                    $book = ChequeBook::query()->find($state);
                    if ($book?->hasAvailableLeaves()) {
                        $set('cheque_number', (string) $book->next_number);
                        $set('bank_account_id', $book->bank_account_id);
                    }
                }),

            TextInput::make('cheque_number')
                ->label('Cheque number')
                ->required()
                ->maxLength(50)
                ->readOnly(fn (Get $get): bool => $get('direction') === ChequeDirection::Outgoing->value && filled($get('cheque_book_id'))),

            DatePicker::make('cheque_date')
                ->label('Cheque date')
                ->default(now())
                ->required()
                ->displayFormat('d/m/Y'),

            TextInput::make('amount')
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->step(0.01),

            TextInput::make('payee_name')
                ->label('Payee')
                ->visible(fn (Get $get): bool => $get('direction') === ChequeDirection::Outgoing->value)
                ->required(fn (Get $get): bool => $get('direction') === ChequeDirection::Outgoing->value)
                ->maxLength(255),

            TextInput::make('payer_name')
                ->label('Payer')
                ->visible(fn (Get $get): bool => $get('direction') === ChequeDirection::Incoming->value)
                ->required(fn (Get $get): bool => $get('direction') === ChequeDirection::Incoming->value)
                ->maxLength(255),

            Select::make('vendor_id')
                ->label('Vendor (optional)')
                ->options(fn () => self::vendorOptions())
                ->searchable()
                ->visible(fn (Get $get): bool => $get('direction') === ChequeDirection::Outgoing->value)
                ->native(false),

            Select::make('customer_id')
                ->label('Customer (optional)')
                ->options(fn () => self::customerOptions())
                ->searchable()
                ->visible(fn (Get $get): bool => $get('direction') === ChequeDirection::Incoming->value)
                ->native(false),

            Select::make('counter_account_id')
                ->label('Counter account')
                ->helperText('Other side of the journal entry when the cheque clears (e.g. expense, payable, receivable).')
                ->options(fn () => self::ledgerOptions())
                ->searchable()
                ->required()
                ->native(false),

            Textarea::make('notes')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function bankOptions(): array
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return [];
        }

        return LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('is_bank', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (LedgerAccount $account): array => [
                $account->id => $account->bankLabel(),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function chequeBookOptions(?string $bankAccountId): array
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return [];
        }

        return ChequeBook::query()
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->when($bankAccountId, fn ($q) => $q->where('bank_account_id', $bankAccountId))
            ->whereColumn('next_number', '<=', 'end_number')
            ->with('bankAccount')
            ->orderBy('book_no')
            ->get()
            ->mapWithKeys(fn (ChequeBook $book): array => [
                $book->id => $book->label(),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function ledgerOptions(): array
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

    /**
     * @return array<string, string>
     */
    private static function vendorOptions(): array
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return [];
        }

        return Vendor::query()
            ->where('merchant_id', $merchantId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function customerOptions(): array
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return [];
        }

        return Customer::query()
            ->where('merchant_id', $merchantId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
