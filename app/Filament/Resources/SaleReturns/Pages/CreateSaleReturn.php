<?php

namespace App\Filament\Resources\SaleReturns\Pages;

use App\Filament\Resources\SaleReturns\SaleReturnResource;
use App\Models\Sale;
use App\Services\SaleReturnService;
use Exception;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSaleReturn extends CreateRecord
{
    protected static string $resource = SaleReturnResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Sale returned';
    }

    protected function handleRecordCreation(array $data): Model
    {
        $sale = Sale::query()->withoutTrashed()->find($data['sale_id'] ?? null);

        if (! $sale) {
            throw ValidationException::withMessages([
                'data.sale_id' => 'Select a sale to return items from.',
            ]);
        }

        try {
            return SaleReturnService::createReturn($sale, $data);
        } catch (Exception $exception) {
            throw ValidationException::withMessages([
                'data.items' => $exception->getMessage(),
            ]);
        }
    }
}
