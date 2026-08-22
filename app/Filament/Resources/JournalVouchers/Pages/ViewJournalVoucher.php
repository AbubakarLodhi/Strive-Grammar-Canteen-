<?php

namespace App\Filament\Resources\JournalVouchers\Pages;

use App\Filament\Resources\JournalVouchers\JournalVoucherResource;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewJournalVoucher extends ViewRecord
{
    protected static string $resource = JournalVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('post')
                ->label('Post voucher')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => FinanceAccess::can('journal_vouchers', 'update') && ! $this->record->isPosted())
                ->action(function (): void {
                    $this->record = app(FinanceLedger::class)->postVoucher($this->record->fresh(['lines.ledgerAccount']));
                    Notification::make()->title('Journal voucher posted.')->success()->send();
                }),
            EditAction::make()
                ->visible(fn (): bool => FinanceAccess::can('journal_vouchers', 'update') && ! $this->record->isPosted()),
        ];
    }
}
