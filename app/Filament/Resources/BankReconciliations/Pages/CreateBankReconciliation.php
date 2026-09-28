<?php

namespace App\Filament\Resources\BankReconciliations\Pages;

use App\Enums\BankReconciliationStatus;
use App\Filament\Resources\BankReconciliations\BankReconciliationResource;
use App\Services\Finance\BankReconciliationService;
use App\Support\FinanceAccess;
use Filament\Resources\Pages\CreateRecord;

class CreateBankReconciliation extends CreateRecord
{
    protected static string $resource = BankReconciliationResource::class;

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
        $service = app(BankReconciliationService::class);

        $data['merchant_id'] = FinanceAccess::merchantId();
        $data['created_by'] = FinanceAccess::createdBy();
        $data['status'] = BankReconciliationStatus::InProgress->value;
        $data['recon_no'] = $service->nextReconNo((string) $data['merchant_id']);

        return $data;
    }

    protected function afterCreate(): void
    {
        app(BankReconciliationService::class)->seedLines($this->record->fresh());
    }
}
