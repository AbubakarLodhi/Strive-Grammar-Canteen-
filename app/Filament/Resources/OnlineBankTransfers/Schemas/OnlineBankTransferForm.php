<?php

namespace App\Filament\Resources\OnlineBankTransfers\Schemas;

use App\Models\LedgerAccount;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OnlineBankTransferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('merchant_id')
                ->default(fn () => FinanceAccess::merchantId())
                ->required(),

            TextInput::make('transfer_no')
                ->label('Transfer No')
                ->default(fn (): string => app(FinanceLedger::class)->nextOnlineTransferNo((string) FinanceAccess::merchantId()))
                ->required()
                ->maxLength(50),

            DatePicker::make('transfer_date')
                ->label('Date')
                ->default(now())
                ->required()
                ->displayFormat('d/m/Y'),

            Select::make('from_account_id')
                ->label('From account')
                ->options(fn (): array => self::cashOrBankOptions())
                ->searchable()
                ->required()
                ->native(false),

            Select::make('to_account_id')
                ->label('To account')
                ->options(fn (): array => self::cashOrBankOptions())
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
}
