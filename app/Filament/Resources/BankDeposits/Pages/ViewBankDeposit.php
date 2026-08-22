<?php

namespace App\Filament\Resources\BankDeposits\Pages;

use App\Filament\Resources\BankDeposits\BankDepositResource;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewBankDeposit extends ViewRecord
{
    protected static string $resource = BankDepositResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printSlip')
                ->label('Print UBL slip')
                ->url(fn (): string => route('bank-deposits.slip', $this->record))
                ->openUrlInNewTab(),
            Action::make('post')
                ->label('Post deposit')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('This posts the amount to the UBL bank ledger. Enter the UBL slip number first.')
                ->visible(fn (): bool => FinanceAccess::can('bank_deposits', 'update') && ! $this->record->isPosted())
                ->action(function (): void {
                    try {
                        $this->record = app(FinanceLedger::class)->postBankDeposit($this->record->fresh(['bankAccount', 'sourceAccount']));
                        Notification::make()->title('UBL deposit posted to the bank ledger.')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            EditAction::make()
                ->visible(fn (): bool => FinanceAccess::can('bank_deposits', 'update') && ! $this->record->isPosted()),
        ];
    }
}
