<?php

namespace App\Filament\Resources\OnlineBankTransfers\Pages;

use App\Filament\Resources\OnlineBankTransfers\OnlineBankTransferResource;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewOnlineBankTransfer extends ViewRecord
{
    protected static string $resource = OnlineBankTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('post')
                ->label('Post transfer')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('This posts the online transfer to the ledger.')
                ->visible(fn (): bool => FinanceAccess::can('online_transfers', 'update') && ! $this->record->isPosted())
                ->action(function (): void {
                    try {
                        $this->record = app(FinanceLedger::class)->postOnlineBankTransfer(
                            $this->record->fresh(['fromAccount', 'toAccount']),
                        );
                        Notification::make()->title('Online transfer posted.')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            EditAction::make()
                ->visible(fn (): bool => FinanceAccess::can('online_transfers', 'update') && ! $this->record->isPosted()),
        ];
    }
}
