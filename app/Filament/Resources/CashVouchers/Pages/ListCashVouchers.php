<?php

namespace App\Filament\Resources\CashVouchers\Pages;

use App\Filament\Resources\CashVouchers\CashVoucherResource;
use App\Support\FinanceAccess;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCashVouchers extends ListRecords
{
    protected static string $resource = CashVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => FinanceAccess::can('cash_vouchers', 'create')),
        ];
    }
}
