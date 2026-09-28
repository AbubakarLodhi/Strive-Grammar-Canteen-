<?php

namespace App\Filament\Resources\BankCheques\Pages;

use App\Enums\ChequeStatus;
use App\Filament\Resources\BankCheques\BankChequeResource;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewBankCheque extends ViewRecord
{
    protected static string $resource = BankChequeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clear')
                ->label('Clear')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('This will post the cheque to the bank ledger.')
                ->visible(fn (): bool => $this->record->isPending()
                    && FinanceAccess::can('bank_cheques', 'update'))
                ->action(function (): void {
                    $this->runAction('clear');
                }),
            Action::make('bounce')
                ->label('Bounce')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => in_array($this->record->status, [ChequeStatus::Pending, ChequeStatus::Cleared], true)
                    && FinanceAccess::can('bank_cheques', 'update'))
                ->action(function (): void {
                    $this->runAction('bounce');
                }),
            Action::make('cancel')
                ->label('Cancel')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->isPending()
                    && FinanceAccess::can('bank_cheques', 'update'))
                ->action(function (): void {
                    $this->runAction('cancel');
                }),
            EditAction::make()
                ->visible(fn (): bool => $this->record->isPending()
                    && FinanceAccess::can('bank_cheques', 'update')),
        ];
    }

    private function runAction(string $action): void
    {
        try {
            $ledger = app(FinanceLedger::class);
            $fresh = $this->record->fresh(['bankAccount', 'counterAccount']);

            $this->record = match ($action) {
                'clear' => $ledger->clearBankCheque($fresh),
                'bounce' => $ledger->bounceBankCheque($fresh),
                'cancel' => $ledger->cancelBankCheque($fresh),
                default => $fresh,
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
