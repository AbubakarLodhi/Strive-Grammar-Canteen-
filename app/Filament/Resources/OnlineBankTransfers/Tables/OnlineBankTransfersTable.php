<?php

namespace App\Filament\Resources\OnlineBankTransfers\Tables;

use App\Enums\FinanceDocumentStatus;
use App\Filament\Resources\OnlineBankTransfers\OnlineBankTransferResource;
use App\Models\LedgerAccount;
use App\Models\OnlineBankTransfer;
use App\Support\FinanceAccess;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OnlineBankTransfersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transfer_no')
                    ->label('Transfer No')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('transfer_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('fromAccount.name')
                    ->label('From')
                    ->formatStateUsing(fn ($state, OnlineBankTransfer $record): string => self::accountLabel($record->fromAccount, (string) $state)),
                TextColumn::make('toAccount.name')
                    ->label('To')
                    ->formatStateUsing(fn ($state, OnlineBankTransfer $record): string => self::accountLabel($record->toAccount, (string) $state)),
                TextColumn::make('amount')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (FinanceDocumentStatus|string $state): string => $state instanceof FinanceDocumentStatus ? $state->label() : $state)
                    ->color(fn (FinanceDocumentStatus|string $state): string => ($state instanceof FinanceDocumentStatus ? $state : FinanceDocumentStatus::tryFrom((string) $state))?->value === 'posted' ? 'success' : 'warning'),
            ])
            ->defaultSort('transfer_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(FinanceDocumentStatus::options()),
            ])
            ->recordActions([
                ViewAction::make()->label('')->tooltip('View'),
                EditAction::make()
                    ->color('warning')
                    ->label('')
                    ->tooltip('Edit')
                    ->visible(fn (OnlineBankTransfer $record): bool => FinanceAccess::can('online_transfers', 'update') && ! $record->isPosted()),
                DeleteAction::make()
                    ->color('danger')
                    ->label('')
                    ->tooltip('Delete')
                    ->visible(fn (OnlineBankTransfer $record): bool => FinanceAccess::can('online_transfers', 'delete') && ! $record->isPosted()),
            ])
            ->recordUrl(fn (OnlineBankTransfer $record): string => OnlineBankTransferResource::getUrl('view', ['record' => $record]));
    }

    private static function accountLabel(?LedgerAccount $account, string $fallback): string
    {
        if (! $account) {
            return $fallback;
        }

        return $account->is_bank ? $account->bankLabel() : $account->name;
    }
}
