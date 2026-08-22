<?php

namespace App\Filament\Resources\BankAccounts\Schemas;

use App\Models\LedgerAccount;
use App\Support\FinanceAccess;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class BankAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }

    /**
     * @return list<Component>
     */
    public static function components(bool $forQuickCreate = false): array
    {
        $fields = [
            Hidden::make('merchant_id')
                ->default(fn () => FinanceAccess::merchantId())
                ->required(),

            TextInput::make('name')
                ->label('Bank name')
                ->placeholder('e.g. Meezan Bank, HBL, Allied Bank')
                ->required()
                ->maxLength(255),

            TextInput::make('account_number')
                ->label('Account number')
                ->placeholder('Bank account number')
                ->required()
                ->maxLength(50)
                ->unique(
                    table: LedgerAccount::class,
                    column: 'account_number',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule
                        ->where('merchant_id', FinanceAccess::merchantId())
                        ->where('is_bank', true)
                        ->withoutTrashed(),
                ),

            TextInput::make('opening_balance')
                ->label('Opening balance')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->step(0.01),
        ];

        if (! $forQuickCreate) {
            $fields[] = Toggle::make('is_active')
                ->label('Active')
                ->default(true);
        }

        return $fields;
    }
}
