<?php

namespace App\Filament\Resources\ChequeBooks\Pages;

use App\Filament\Resources\ChequeBooks\ChequeBookResource;
use App\Support\FinanceAccess;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateChequeBook extends CreateRecord
{
    protected static string $resource = ChequeBookResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['merchant_id'] = FinanceAccess::merchantId();
        $data['created_by'] = FinanceAccess::createdBy();

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

        $data['next_number'] = $next;

        return $data;
    }
}
