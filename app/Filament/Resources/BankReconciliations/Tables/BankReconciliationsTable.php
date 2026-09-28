<?php

namespace App\Filament\Resources\BankReconciliations\Tables;

use App\Enums\BankReconciliationStatus;
use App\Filament\Resources\BankReconciliations\BankReconciliationResource;
use App\Models\BankReconciliation;
use App\Support\FinanceAccess;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BankReconciliationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('recon_no')
                    ->label('Recon No.')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bankAccount.name')
                    ->label('Bank')
                    ->formatStateUsing(fn ($state, BankReconciliation $record): string => $record->bankAccount?->bankLabel() ?? (string) $state),
                TextColumn::make('period_from')->date('d/m/Y'),
                TextColumn::make('period_to')->date('d/m/Y'),
                TextColumn::make('statement_closing')
                    ->label('Stmt closing')
                    ->money('PKR'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BankReconciliationStatus|string $state): string => $state instanceof BankReconciliationStatus ? $state->label() : (string) $state)
                    ->color(fn (BankReconciliationStatus|string $state): string => ($state instanceof BankReconciliationStatus ? $state : BankReconciliationStatus::tryFrom((string) $state)) === BankReconciliationStatus::Completed
                        ? 'success'
                        : 'warning'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(BankReconciliationStatus::options()),
            ])
            ->recordActions([
                ViewAction::make()->label('')->tooltip('View'),
                DeleteAction::make()
                    ->label('')
                    ->tooltip('Delete')
                    ->visible(fn (BankReconciliation $record): bool => ! $record->isCompleted()
                        && FinanceAccess::can('bank_reconciliations', 'delete')),
            ])
            ->recordUrl(fn (BankReconciliation $record): string => BankReconciliationResource::getUrl('view', ['record' => $record]));
    }
}
