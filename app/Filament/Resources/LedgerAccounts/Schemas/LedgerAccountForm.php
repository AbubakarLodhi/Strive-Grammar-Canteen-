<?php

namespace App\Filament\Resources\LedgerAccounts\Schemas;

use App\Enums\LedgerAccountType;
use App\Support\FinanceAccess;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LedgerAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('merchant_id')
                ->default(fn () => FinanceAccess::merchantId())
                ->required(),

            TextInput::make('name')
                ->label('Account Name')
                ->required()
                ->maxLength(255),

            Select::make('type')
                ->label('Type')
                ->options(LedgerAccountType::options())
                ->required()
                ->native(false),

            TextInput::make('opening_balance')
                ->label('Opening Balance')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->step(0.01),

            Toggle::make('is_bank')
                ->label('Bank Account')
                ->helperText('Use this account for bank deposits.')
                ->default(false)
                ->live(),

            TextInput::make('account_number')
                ->label('Account number')
                ->maxLength(50)
                ->visible(fn (callable $get): bool => (bool) $get('is_bank'))
                ->required(fn (callable $get): bool => (bool) $get('is_bank')),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ]);
    }
}
