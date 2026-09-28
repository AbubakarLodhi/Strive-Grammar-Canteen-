<?php

namespace App\Filament\Resources\ChequeBooks\Pages;

use App\Filament\Resources\ChequeBooks\ChequeBookResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditChequeBook extends EditRecord
{
    protected static string $resource = ChequeBookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => ! $this->record->cheques()->exists()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $start = (int) ($data['start_number'] ?? 0);
        $end = (int) ($data['end_number'] ?? 0);
        $next = (int) ($data['next_number'] ?? $start);

        if ($end < $start) {
            throw ValidationException::withMessages([
                'end_number' => 'End leaf must be greater than or equal to start leaf.',
            ]);
        }

        if ($next < $start || $next > $end + 1) {
            throw ValidationException::withMessages([
                'next_number' => 'Next leaf must be within the book range.',
            ]);
        }

        return $data;
    }
}
