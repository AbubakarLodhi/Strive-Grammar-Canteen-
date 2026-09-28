<?php

namespace App\Filament\Resources\BankReconciliations\Schemas;

use App\Models\LedgerAccount;
use App\Support\FinanceAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BankReconciliationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('merchant_id')
                ->default(fn () => FinanceAccess::merchantId())
                ->required(),

            Select::make('bank_account_id')
                ->label('Bank account')
                ->options(fn () => self::bankOptions())
                ->searchable()
                ->required()
                ->native(false),

            DatePicker::make('period_from')
                ->label('Period from')
                ->required()
                ->displayFormat('d/m/Y'),

            DatePicker::make('period_to')
                ->label('Period to')
                ->required()
                ->displayFormat('d/m/Y')
                ->afterOrEqual('period_from'),

            TextInput::make('statement_opening')
                ->label('Statement opening balance')
                ->numeric()
                ->required()
                ->default(0)
                ->step(0.01),

            TextInput::make('statement_closing')
                ->label('Statement closing balance')
                ->numeric()
                ->required()
                ->default(0)
                ->step(0.01),

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
}
