<?php

namespace App\Filament\Resources\JournalVouchers\Schemas;

use App\Models\LedgerAccount;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JournalVoucherForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('merchant_id')
                ->default(fn () => FinanceAccess::merchantId())
                ->required(),

            Section::make('Voucher')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('voucher_no')
                        ->label('Voucher No')
                        ->default(fn (): string => app(FinanceLedger::class)->nextVoucherNo((string) FinanceAccess::merchantId()))
                        ->required()
                        ->maxLength(50),
                    DatePicker::make('voucher_date')
                        ->label('Date')
                        ->default(now())
                        ->required()
                        ->displayFormat('d/m/Y'),
                    Textarea::make('narration')
                        ->label('Narration')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Entries')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('lines')
                        ->label('Journal Lines')
                        ->minItems(2)
                        ->defaultItems(2)
                        ->columns(12)
                        ->schema([
                            Select::make('ledger_account_id')
                                ->label('Account')
                                ->options(fn () => self::accountOptions())
                                ->searchable()
                                ->required()
                                ->columnSpan(5),
                            TextInput::make('description')
                                ->label('Description')
                                ->maxLength(255)
                                ->columnSpan(3),
                            TextInput::make('debit')
                                ->label('Debit')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->step(0.01)
                                ->columnSpan(2),
                            TextInput::make('credit')
                                ->label('Credit')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->step(0.01)
                                ->columnSpan(2),
                        ])
                        ->required(),
                ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function accountOptions(): array
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return [];
        }

        return LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (LedgerAccount $account): array => [
                $account->id => $account->name,
            ])
            ->all();
    }
}
