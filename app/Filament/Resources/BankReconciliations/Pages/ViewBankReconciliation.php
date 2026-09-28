<?php

namespace App\Filament\Resources\BankReconciliations\Pages;

use App\Filament\Resources\BankReconciliations\BankReconciliationResource;
use App\Models\BankReconciliationItem;
use App\Services\Finance\BankReconciliationService;
use App\Support\FinanceAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class ViewBankReconciliation extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = BankReconciliationResource::class;

    protected string $view = 'filament.resources.bank-reconciliations.pages.view-bank-reconciliation';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshLines')
                ->label('Refresh lines')
                ->color('gray')
                ->visible(fn (): bool => ! $this->record->isCompleted()
                    && FinanceAccess::can('bank_reconciliations', 'update'))
                ->action(function (): void {
                    app(BankReconciliationService::class)->seedLines($this->record->fresh());
                    Notification::make()->title('Lines refreshed')->success()->send();
                }),
            Action::make('complete')
                ->label('Complete reconciliation')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Difference must be zero. Matched lines will be locked.')
                ->visible(fn (): bool => ! $this->record->isCompleted()
                    && FinanceAccess::can('bank_reconciliations', 'update'))
                ->action(function (): void {
                    try {
                        $this->record = app(BankReconciliationService::class)->complete($this->record->fresh(['items.journalVoucherLine']));
                        Notification::make()->title('Reconciliation completed')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    /**
     * @return array{matched_net: float, expected_closing: float, difference: float}
     */
    public function getTotals(): array
    {
        return app(BankReconciliationService::class)->totals(
            $this->record->fresh(['items.journalVoucherLine']) ?? $this->record
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                BankReconciliationItem::query()
                    ->where('bank_reconciliation_id', $this->record->id)
                    ->with(['journalVoucherLine.journalVoucher'])
            )
            ->columns([
                TextColumn::make('journalVoucherLine.journalVoucher.voucher_date')
                    ->label('Date')
                    ->date('d/m/Y'),
                TextColumn::make('journalVoucherLine.journalVoucher.voucher_no')
                    ->label('Voucher'),
                TextColumn::make('journalVoucherLine.description')
                    ->label('Description')
                    ->limit(40),
                TextColumn::make('journalVoucherLine.debit')
                    ->label('Debit')
                    ->money('PKR'),
                TextColumn::make('journalVoucherLine.credit')
                    ->label('Credit')
                    ->money('PKR'),
                IconColumn::make('is_matched')
                    ->label('Matched')
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (BankReconciliationItem $record): string => $record->is_matched ? 'Unmatch' : 'Match')
                    ->color(fn (BankReconciliationItem $record): string => $record->is_matched ? 'gray' : 'success')
                    ->visible(fn (): bool => ! $this->record->isCompleted()
                        && FinanceAccess::can('bank_reconciliations', 'update'))
                    ->action(function (BankReconciliationItem $record): void {
                        app(BankReconciliationService::class)->toggleMatch($record, ! $record->is_matched);
                    }),
            ])
            ->paginated([25, 50, 100]);
    }
}
