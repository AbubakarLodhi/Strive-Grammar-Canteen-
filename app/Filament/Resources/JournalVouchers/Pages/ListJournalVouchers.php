<?php

namespace App\Filament\Resources\JournalVouchers\Pages;

use App\Filament\Resources\JournalVouchers\JournalVoucherResource;
use App\Support\FinanceAccess;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJournalVouchers extends ListRecords
{
    protected static string $resource = JournalVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => FinanceAccess::can('journal_vouchers', 'create')),
        ];
    }
}
