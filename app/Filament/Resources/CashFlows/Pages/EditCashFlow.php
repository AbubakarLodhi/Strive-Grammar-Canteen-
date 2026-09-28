<?php

namespace App\Filament\Resources\CashFlows\Pages;

use App\Filament\Resources\CashFlows\CashFlowResource;
use App\Models\Branch;
use App\Models\CashFlow;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditCashFlow extends EditRecord
{
    protected static string $resource = CashFlowResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())->user()?->hasPermissionTo('cash_flows.delete', Filament::getCurrentPanel()->getAuthGuard())),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! filled($data['business_id'] ?? null) && filled($data['branch_id'] ?? null)) {
            $data['business_id'] = Branch::query()->whereKey($data['branch_id'])->value('business_id');
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
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
