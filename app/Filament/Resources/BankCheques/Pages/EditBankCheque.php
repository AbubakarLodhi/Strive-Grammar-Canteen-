<?php

namespace App\Filament\Resources\BankCheques\Pages;

use App\Filament\Resources\BankCheques\BankChequeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBankCheque extends EditRecord
{
    protected static string $resource = BankChequeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => $this->record->isPending()),
        ];
    }
}
