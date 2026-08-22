<?php

namespace App\Filament\Resources\Payrolls\Pages;

use App\Filament\Resources\Payrolls\PayrollResource;
use App\Models\Merchant;
use App\Models\Payroll;
use App\Models\User;
use App\Services\Finance\OperationalLedgerPoster;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreatePayroll extends CreateRecord
{
    protected static string $resource = PayrollResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['allowances'] = $data['allowances'] ?? [];
        $data['deductions'] = $data['deductions'] ?? [];

        $authUser = Filament::auth()->user();

        if ($authUser instanceof User) {
            $merchantId = $authUser->merchant_id;
            $data['created_by'] = $authUser->id;
        } elseif ($authUser instanceof Merchant) {
            $merchantId = $authUser->id;
            $data['created_by'] = null;
        } else {
            Notification::make()
                ->title('Invalid creator')
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'user_id' => 'Invalid creator context.',
            ]);
        }

        $data['merchant_id'] = $merchantId;

        $exists = Payroll::query()
            ->where('merchant_id', $merchantId)
            ->where('user_id', $data['user_id'])
            ->where('period_month', $data['period_month'])
            ->where('period_year', $data['period_year'])
            ->exists();

        if ($exists) {
            Notification::make()
                ->title('Duplicate payroll')
                ->body('A payroll already exists for this employee and period.')
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'period_year' => 'Payroll for this employee and period already exists.',
            ]);
        }

        return $data;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Pre-fill user_id from query param if passed
        if (request()->has('user_id') && empty($data['user_id'])) {
            $data['user_id'] = request()->get('user_id');
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->record instanceof Payroll) {
            app(OperationalLedgerPoster::class)->syncPayroll($this->record);
        }
    }
}
