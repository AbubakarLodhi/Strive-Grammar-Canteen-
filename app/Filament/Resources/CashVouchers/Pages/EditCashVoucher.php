<?php

namespace App\Filament\Resources\CashVouchers\Pages;

use App\Filament\Resources\CashVouchers\CashVoucherResource;
use App\Support\FinanceAccess;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCashVoucher extends EditRecord
{
    protected static string $resource = CashVoucherResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => FinanceAccess::can('cash_vouchers', 'delete') && ! $this->record->isPosted()),
        ];
    }
}
