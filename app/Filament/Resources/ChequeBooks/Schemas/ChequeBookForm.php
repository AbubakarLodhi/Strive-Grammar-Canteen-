<?php

namespace App\Filament\Resources\ChequeBooks\Schemas;

use App\Models\LedgerAccount;
use App\Support\FinanceAccess;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ChequeBookForm
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

            TextInput::make('book_no')
                ->label('Book number / label')
                ->required()
                ->maxLength(50),

            TextInput::make('start_number')
                ->label('Start leaf')
                ->numeric()
                ->required()
                ->minValue(1)
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                    if (blank($get('next_number'))) {
                        $set('next_number', $state);
                    }
                }),

            TextInput::make('end_number')
                ->label('End leaf')
                ->numeric()
                ->required()
                ->minValue(1)
                ->gte('start_number'),

            TextInput::make('next_number')
                ->label('Next leaf')
                ->numeric()
                ->required()
                ->minValue(1)
                ->helperText('Next cheque number to issue from this book.'),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true),

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
