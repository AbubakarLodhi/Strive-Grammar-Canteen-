<?php

namespace App\Filament\Resources\OnlineBankTransfers\Pages;

use App\Enums\FinanceDocumentStatus;
use App\Filament\Resources\OnlineBankTransfers\OnlineBankTransferResource;
use App\Support\FinanceAccess;
use Filament\Resources\Pages\CreateRecord;

class CreateOnlineBankTransfer extends CreateRecord
{
    protected static string $resource = OnlineBankTransferResource::class;

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
}
