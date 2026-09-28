<?php

namespace App\Filament\Resources\CashVouchers\Tables;

use App\Enums\CashVoucherDirection;
use App\Enums\FinanceDocumentStatus;
use App\Filament\Resources\CashVouchers\CashVoucherResource;
use App\Models\CashVoucher;
use App\Support\FinanceAccess;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CashVouchersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('voucher_no')
                    ->label('Voucher No')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('voucher_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('direction')
                    ->badge()
                    ->formatStateUsing(fn (CashVoucherDirection|string $state): string => $state instanceof CashVoucherDirection ? $state->label() : (string) $state)
                    ->color(fn (CashVoucherDirection|string $state): string => ($state instanceof CashVoucherDirection ? $state : CashVoucherDirection::tryFrom((string) $state)) === CashVoucherDirection::Receiving ? 'success' : 'info'),
                TextColumn::make('cashAccount.name')
                    ->label('Cash / bank')
                    ->formatStateUsing(fn ($state, CashVoucher $record): string => $record->cashAccount?->is_bank
                        ? ($record->cashAccount->bankLabel())
                        : (string) ($record->cashAccount?->name ?? $state)),
                TextColumn::make('counterAccount.name')
                    ->label('Counter')
                    ->formatStateUsing(fn ($state, CashVoucher $record): string => $record->counterAccount
                        ? $record->counterAccount->code.' — '.$record->counterAccount->name
                        : (string) $state),
                TextColumn::make('amount')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (FinanceDocumentStatus|string $state): string => $state instanceof FinanceDocumentStatus ? $state->label() : $state)
                    ->color(fn (FinanceDocumentStatus|string $state): string => ($state instanceof FinanceDocumentStatus ? $state : FinanceDocumentStatus::tryFrom((string) $state))?->value === 'posted' ? 'success' : 'warning'),
            ])
            ->defaultSort('voucher_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(FinanceDocumentStatus::options()),
                SelectFilter::make('direction')
                    ->options(CashVoucherDirection::options()),
            ])
            ->recordActions([
                ViewAction::make()->label('')->tooltip('View'),
                EditAction::make()
                    ->color('warning')
                    ->label('')
                    ->tooltip('Edit')
                    ->visible(fn (CashVoucher $record): bool => FinanceAccess::can('cash_vouchers', 'update') && ! $record->isPosted()),
                DeleteAction::make()
                    ->color('danger')
                    ->label('')
                    ->tooltip('Delete')
                    ->visible(fn (CashVoucher $record): bool => FinanceAccess::can('cash_vouchers', 'delete') && ! $record->isPosted()),
            ])
            ->recordUrl(fn (CashVoucher $record): string => CashVoucherResource::getUrl('view', ['record' => $record]));
    }
}
