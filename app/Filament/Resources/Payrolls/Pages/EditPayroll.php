<?php

namespace App\Filament\Resources\Payrolls\Pages;

use App\Filament\Resources\Payrolls\PayrollResource;
use App\Services\Finance\OperationalLedgerPoster;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditPayroll extends EditRecord
{
    protected static string $resource = PayrollResource::class;

    public function getTitle(): string
    {
        $name = (string) ($this->record?->name ?? '');

        return 'Edit '.Str::limit($name, 30);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        $guard = Filament::getCurrentPanel()->getAuthGuard();

        return [
            ViewAction::make()
                ->visible(fn () => auth($guard)->user()?->hasPermissionTo('payrolls.view', $guard)),

            DeleteAction::make()
                ->color('danger')
                ->visible(fn () => auth($guard)->user()?->hasPermissionTo('payrolls.delete', $guard)),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['allowances'] = $data['allowances'] ?? [];
        $data['deductions'] = $data['deductions'] ?? [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['allowances'] = $data['allowances'] ?? [];
        $data['deductions'] = $data['deductions'] ?? [];

        return $data;
    }

    protected function afterSave(): void
    {
        app(OperationalLedgerPoster::class)->syncPayroll($this->record->fresh());
    }
}
