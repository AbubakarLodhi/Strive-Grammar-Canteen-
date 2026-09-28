<?php

namespace App\Filament\Resources\OnlineBankTransfers\Pages;

use App\Filament\Resources\OnlineBankTransfers\OnlineBankTransferResource;
use App\Support\FinanceAccess;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOnlineBankTransfer extends EditRecord
{
    protected static string $resource = OnlineBankTransferResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => FinanceAccess::can('online_transfers', 'delete') && ! $this->record->isPosted()),
        ];
    }
}
