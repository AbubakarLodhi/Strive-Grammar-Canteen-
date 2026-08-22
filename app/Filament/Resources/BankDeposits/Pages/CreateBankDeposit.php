<?php

namespace App\Filament\Resources\BankDeposits\Pages;

use App\Enums\FinanceDocumentStatus;
use App\Filament\Resources\BankDeposits\BankDepositResource;
use App\Support\FinanceAccess;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateBankDeposit extends CreateRecord
{
    protected static string $resource = BankDepositResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['merchant_id'] = FinanceAccess::merchantId();
        $data['created_by'] = FinanceAccess::createdBy();
        $data['status'] = FinanceDocumentStatus::Draft;

        return $data;
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Deposit slip '.$this->record->deposit_no.' is ready to print.')
            ->body('Print the deposit slip, deposit the cash, then enter the bank slip number and post.')
            ->success()
            ->send();
    }
}
