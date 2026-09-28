<?php

namespace App\Filament\Resources\BankCheques\Tables;

use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Filament\Resources\BankCheques\BankChequeResource;
use App\Models\BankCheque;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class BankChequesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cheque_number')
                    ->label('Cheque No.')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('cheque_date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('direction')
                    ->badge()
                    ->formatStateUsing(fn (ChequeDirection|string $state): string => $state instanceof ChequeDirection ? $state->label() : (string) $state),
                TextColumn::make('bankAccount.name')
                    ->label('Bank')
                    ->formatStateUsing(fn ($state, BankCheque $record): string => $record->bankAccount?->bankLabel() ?? (string) $state),
                TextColumn::make('amount')
                    ->money('PKR')
                    ->sortable(),
                TextColumn::make('payee_name')
                    ->label('Payee / Payer')
                    ->getStateUsing(fn (BankCheque $record): string => $record->isOutgoing()
                        ? (string) ($record->payee_name ?: '—')
                        : (string) ($record->payer_name ?: '—')),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ChequeStatus|string $state): string => $state instanceof ChequeStatus ? $state->label() : (string) $state)
                    ->color(fn (ChequeStatus|string $state): string => match ($state instanceof ChequeStatus ? $state : ChequeStatus::tryFrom((string) $state)) {
                        ChequeStatus::Pending => 'warning',
                        ChequeStatus::Cleared => 'success',
                        ChequeStatus::Bounced => 'danger',
                        ChequeStatus::Cancelled => 'gray',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('cheque_date', 'desc')
            ->filters([
                SelectFilter::make('direction')->options(ChequeDirection::options()),
                SelectFilter::make('status')->options(ChequeStatus::options()),
            ])
            ->recordActions([
                ViewAction::make()->label('')->tooltip('View'),
                Action::make('clear')
                    ->label('')
                    ->icon('heroicon-s-check-circle')
                    ->color('success')
                    ->tooltip('Clear')
                    ->requiresConfirmation()
                    ->modalHeading('Clear cheque')
                    ->modalDescription('This will post the cheque to the bank ledger.')
                    ->visible(fn (BankCheque $record): bool => $record->isPending()
                        && FinanceAccess::can('bank_cheques', 'update'))
                    ->action(fn (BankCheque $record) => self::runLedgerAction($record, 'clear')),
                Action::make('bounce')
                    ->label('')
                    ->icon('heroicon-s-x-circle')
                    ->color('danger')
                    ->tooltip('Bounce')
                    ->requiresConfirmation()
                    ->visible(fn (BankCheque $record): bool => in_array($record->status, [ChequeStatus::Pending, ChequeStatus::Cleared], true)
                        && FinanceAccess::can('bank_cheques', 'update'))
                    ->action(fn (BankCheque $record) => self::runLedgerAction($record, 'bounce')),
                Action::make('cancel')
                    ->label('')
                    ->icon('heroicon-s-no-symbol')
                    ->color('gray')
                    ->tooltip('Cancel')
                    ->requiresConfirmation()
                    ->visible(fn (BankCheque $record): bool => $record->isPending()
                        && FinanceAccess::can('bank_cheques', 'update'))
                    ->action(fn (BankCheque $record) => self::runLedgerAction($record, 'cancel')),
                EditAction::make()
                    ->label('')
                    ->tooltip('Edit')
                    ->visible(fn (BankCheque $record): bool => $record->isPending()
                        && FinanceAccess::can('bank_cheques', 'update')),
                DeleteAction::make()
                    ->label('')
                    ->tooltip('Delete')
                    ->visible(fn (BankCheque $record): bool => $record->isPending()
                        && FinanceAccess::can('bank_cheques', 'delete')),
            ])
            ->recordUrl(fn (BankCheque $record): string => BankChequeResource::getUrl('view', ['record' => $record]));
    }

    private static function runLedgerAction(BankCheque $record, string $action): void
    {
        try {
            $ledger = app(FinanceLedger::class);
            $fresh = $record->fresh(['bankAccount', 'counterAccount']);

            match ($action) {
                'clear' => $ledger->clearBankCheque($fresh),
                'bounce' => $ledger->bounceBankCheque($fresh),
                'cancel' => $ledger->cancelBankCheque($fresh),
                default => null,
            };

            Notification::make()
                ->title(match ($action) {
                    'clear' => 'Cheque cleared',
                    'bounce' => 'Cheque bounced',
                    'cancel' => 'Cheque cancelled',
                    default => 'Done',
                })
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
