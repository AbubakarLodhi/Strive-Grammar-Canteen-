<?php

namespace App\Filament\Resources\LedgerAccounts\Schemas;

use App\Enums\LedgerAccountType;
use App\Models\LedgerAccount;
use App\Support\FinanceAccess;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                    if (! filled($state) || filled($get('type'))) {
                        return;
                    }

                    if (preg_match('/\b(expense|expenses|cost|fee|fees|salary|wages|rent|utility|utilities)\b/i', $state)) {
                        $set('type', LedgerAccountType::Expense->value);
                    }
                })
                ->helperText(function (Get $get, ?LedgerAccount $record): ?string {
                    $merchantId = FinanceAccess::merchantId();
                    $name = trim((string) $get('name'));

                    if (! $merchantId || $name === '') {
                        return null;
                    }

                    $similar = LedgerAccount::query()
                        ->where('merchant_id', $merchantId)
                        ->when($record?->id, fn ($q) => $q->whereKeyNot($record->id))
                        ->where(function ($q) use ($name): void {
                            $q->where('name', $name)
                                ->orWhere('name', 'like', '%'.$name.'%');
                        })
                        ->limit(3)
                        ->pluck('name');

                    if ($similar->isEmpty()) {
                        return null;
                    }

                    return 'Similar accounts already exist: '.$similar->implode(', ');
                }),

            Select::make('parent_id')
                ->label('Account group')
                ->helperText('Optional parent group for Chart of Accounts hierarchy.')
                ->searchable()
                ->preload()
                ->native(false)
                ->nullable()
                ->options(function (?LedgerAccount $record): array {
                    $merchantId = FinanceAccess::merchantId();
                    if (! $merchantId) {
                        return [];
                    }

                    return LedgerAccount::query()
                        ->where('merchant_id', $merchantId)
                        ->when($record?->id, fn ($q) => $q->whereKeyNot($record->id))
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (LedgerAccount $account) => [
                            $account->id => trim(($account->code ? $account->code.' — ' : '').$account->name),
                        ])
                        ->all();
                })
                ->rules([
                    fn (?LedgerAccount $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        if (! $value || ! $record?->id) {
                            return;
                        }

                        if ((string) $value === (string) $record->id) {
                            $fail('An account cannot be its own parent.');
                        }
                    },
                ]),

            Select::make('type')
                ->label('Type')
                ->options(LedgerAccountType::options())
                ->required()
                ->native(false)
                ->live()
                ->helperText(function (Get $get): ?string {
                    $name = (string) $get('name');
                    $type = $get('type');

                    if ($name === '' || ! $type) {
                        return null;
                    }

                    $looksExpense = (bool) preg_match('/\b(expense|expenses|cost|fee|fees|salary|wages|rent|utility|utilities)\b/i', $name);
                    if ($looksExpense && $type !== LedgerAccountType::Expense->value) {
                        return 'This name looks like an expense account, but the type is not Expense.';
                    }

                    return null;
                }),

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
