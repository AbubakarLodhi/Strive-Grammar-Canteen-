<?php

namespace App\Filament\Resources\CashFlows\Pages;

use App\Filament\Resources\CashFlows\CashFlowResource;
use App\Models\Branch;
use App\Models\CashFlow;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateCashFlow extends CreateRecord
{
    protected static string $resource = CashFlowResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['business_id'] = filled($data['branch_id'] ?? null)
            ? Branch::query()->whereKey($data['branch_id'])->value('business_id')
            : null;
        $data['method'] = in_array($data['method'] ?? null, ['Cash', 'Bank'], true)
            ? $data['method']
            : 'Cash';
        $data['direction'] = CashFlow::primaryDirectionForFlowType($data['flow_type'] ?? null);
        $data['reference_no'] = null;

        return $data;
    }
}
