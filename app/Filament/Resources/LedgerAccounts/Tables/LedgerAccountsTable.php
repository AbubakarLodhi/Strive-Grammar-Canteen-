<?php

namespace App\Filament\Resources\LedgerAccounts\Tables;

use App\Enums\FinanceDocumentStatus;
use App\Enums\LedgerAccountType;
use App\Filament\Resources\LedgerAccounts\LedgerAccountResource;
use App\Models\LedgerAccount;
use App\Support\FinanceAccess;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LedgerAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): void {
                $query
                    ->with('parent:id,name')
                    ->withSum([
                        'journalLines as posted_debits' => fn (Builder $lines): Builder => $lines->whereHas(
                            'journalVoucher',
                            fn (Builder $voucher): Builder => $voucher->where('status', FinanceDocumentStatus::Posted->value)
                        ),
                    ], 'debit')
                    ->withSum([
                        'journalLines as posted_credits' => fn (Builder $lines): Builder => $lines->whereHas(
                            'journalVoucher',
                            fn (Builder $voucher): Builder => $voucher->where('status', FinanceDocumentStatus::Posted->value)
                        ),
                    ], 'credit');
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Account')
                    ->searchable()
                    ->sortable()
                    ->description(function (LedgerAccount $record): ?string {
                        if ($record->isVendorPayable()) {
                            return 'Vendor payable';
                        }

                        if ($record->parent?->name) {
                            return 'Group: '.$record->parent->name;
                        }

                        return null;
                    }),
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('parent.name')
                    ->label('Parent group')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (LedgerAccountType|string $state): string => $state instanceof LedgerAccountType ? $state->label() : $state)
                    ->sortable(),
                IconColumn::make('is_bank')
                    ->label('Bank')
                    ->boolean(),
                TextColumn::make('account_number')
                    ->label('Account number')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('opening_balance')
                    ->label('Opening')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('posted_debits')
                    ->label('Debit')
                    ->numeric(2)
                    ->placeholder('0.00')
                    ->alignRight(),
                TextColumn::make('posted_credits')
                    ->label('Credit')
                    ->numeric(2)
                    ->placeholder('0.00')
                    ->alignRight(),
                TextColumn::make('posted_balance')
                    ->label('Balance')
                    ->state(fn (LedgerAccount $record): string => $record->postedBalance())
                    ->numeric(2)
                    ->alignRight(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('type')
                    ->options(LedgerAccountType::options()),
                TernaryFilter::make('is_bank')
                    ->label('Bank accounts'),
                TernaryFilter::make('is_active')
                    ->label('Active'),
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
                    ->visible(fn (LedgerAccount $record): bool => FinanceAccess::can('ledger_accounts', 'delete')
                        && ! $record->is_system
                        && ! $record->isVendorPayable()),
            ])
            ->recordUrl(fn (LedgerAccount $record): ?string => FinanceAccess::can('ledger_accounts', 'update')
                ? LedgerAccountResource::getUrl('edit', ['record' => $record])
                : null)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => FinanceAccess::can('ledger_accounts', 'delete')),
                ]),
            ]);
    }
}
