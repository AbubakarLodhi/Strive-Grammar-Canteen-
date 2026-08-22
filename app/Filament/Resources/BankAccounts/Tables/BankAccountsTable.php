<?php

namespace App\Filament\Resources\BankAccounts\Tables;

use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Models\LedgerAccount;
use App\Support\FinanceAccess;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class BankAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Bank')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('account_number')
                    ->label('Account number')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('opening_balance')
                    ->label('Opening')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('posted_balance')
                    ->label('Balance')
                    ->state(fn (LedgerAccount $record): string => $record->postedBalance())
                    ->numeric(2),
                IconColumn::make('is_system')
                    ->label('Default')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make()
                    ->color('warning')
                    ->label('')
                    ->tooltip('Edit')
                    ->visible(fn (): bool => FinanceAccess::can('ledger_accounts', 'update')),
                DeleteAction::make()
                    ->color('danger')
                    ->label('')
                    ->tooltip('Delete')
                    ->visible(fn (LedgerAccount $record): bool => FinanceAccess::can('ledger_accounts', 'delete') && ! $record->is_system),
            ])
            ->recordUrl(fn (LedgerAccount $record): ?string => FinanceAccess::can('ledger_accounts', 'update')
                ? BankAccountResource::getUrl('edit', ['record' => $record])
                : null);
    }
}
