<?php

namespace App\Filament\Resources\BankDeposits\Tables;

use App\Enums\FinanceDocumentStatus;
use App\Filament\Resources\BankDeposits\BankDepositResource;
use App\Models\BankDeposit;
use App\Support\FinanceAccess;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BankDepositsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('deposit_no')
                    ->label('Slip No')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('deposit_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('bankAccount.name')
                    ->label('Bank')
                    ->formatStateUsing(fn ($state, BankDeposit $record): string => $record->bankAccount?->bankLabel() ?? (string) $state),
                TextColumn::make('sourceAccount.name')
                    ->label('From'),
                TextColumn::make('amount')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('reference_no')
                    ->label('Bank slip')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (FinanceDocumentStatus|string $state): string => $state instanceof FinanceDocumentStatus ? $state->label() : $state)
                    ->color(fn (FinanceDocumentStatus|string $state): string => ($state instanceof FinanceDocumentStatus ? $state : FinanceDocumentStatus::tryFrom((string) $state))?->value === 'posted' ? 'success' : 'warning'),
            ])
            ->defaultSort('deposit_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(FinanceDocumentStatus::options()),
            ])
            ->recordActions([
                ViewAction::make()->label('')->tooltip('View'),
                Action::make('slip')
                    ->label('')
                    ->icon('heroicon-o-printer')
                    ->tooltip('Print deposit slip')
                    ->url(fn (BankDeposit $record): string => route('bank-deposits.slip', $record))
                    ->openUrlInNewTab(),
                EditAction::make()
                    ->color('warning')
                    ->label('')
                    ->tooltip('Edit')
                    ->visible(fn (BankDeposit $record): bool => FinanceAccess::can('bank_deposits', 'update') && ! $record->isPosted()),
                DeleteAction::make()
                    ->color('danger')
                    ->label('')
                    ->tooltip('Delete')
                    ->visible(fn (BankDeposit $record): bool => FinanceAccess::can('bank_deposits', 'delete') && ! $record->isPosted()),
            ])
            ->recordUrl(fn (BankDeposit $record): string => BankDepositResource::getUrl('view', ['record' => $record]));
    }
}
