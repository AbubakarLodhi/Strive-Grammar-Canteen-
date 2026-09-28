<?php

namespace App\Filament\Resources\CashVouchers\Pages;

use App\Filament\Resources\CashVouchers\CashVoucherResource;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewCashVoucher extends ViewRecord
{
    protected static string $resource = CashVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('post')
                ->label('Post voucher')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('This posts the cash voucher to the ledger.')
                ->visible(fn (): bool => FinanceAccess::can('cash_vouchers', 'update') && ! $this->record->isPosted())
                ->action(function (): void {
                    try {
                        $this->record = app(FinanceLedger::class)->postCashVoucher(
                            $this->record->fresh(['cashAccount', 'counterAccount']),
                        );
                        Notification::make()->title('Cash voucher posted.')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            EditAction::make()
                ->visible(fn (): bool => FinanceAccess::can('cash_vouchers', 'update') && ! $this->record->isPosted()),
        ];
    }
}
