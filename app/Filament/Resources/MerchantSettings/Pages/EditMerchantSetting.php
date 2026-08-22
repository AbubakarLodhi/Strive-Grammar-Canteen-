<?php

namespace App\Filament\Resources\MerchantSettings\Pages;

use App\Enums\AttachmentMetaType;
use App\Enums\AttachmentType;
use App\Filament\Resources\MerchantSettings\MerchantSettingResource;
use App\Models\MerchantSetting;
use App\Services\Finance\FinanceLedger;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMerchantSetting extends EditRecord
{
    protected static string $resource = MerchantSettingResource::class;

    protected static ?string $title = 'Merchant Settings';

    /** Processed logo path captured in mutateFormDataBeforeSave (file already moved by Filament) */
    private ?string $pendingLogoPath = null;

    /** True when the user explicitly cleared the logo */
    private bool $clearLogo = false;

    protected function resolveRecord($key): Model
    {
        if (auth('merchant')->check()) {
            return MerchantSetting::where(
                'merchant_id',
                auth('merchant')->id()
            )->firstOrFail();
        }

        return parent::resolveRecord($key);
    }

    protected function getHeaderActions(): array
    {
        return auth('merchant')->check()
            ? []
            : [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // ── MERCHANT PANEL ──
        if (auth('merchant')->check()) {
            $merchant = auth('merchant')->user();

            $data['merchant_logo'] = $merchant->logo
                ? [$merchant->logo->photo_url]
                : null;

            $data['profile_photo'] = $merchant->profilePhoto
                ? [$merchant->profilePhoto->photo_url]
                : null;

            $data['cash_in_hand'] = $merchant->cash_in_hand;
            $data['cash_in_bank'] = $merchant->cash_in_bank;

            return $data;
        }

        // ── ADMIN PANEL ──
        if ($this->record?->merchant) {
            $data['merchant_logo'] = $this->record->merchant->logo
                ? [$this->record->merchant->logo->photo_url]
                : null;

            $data['profile_photo'] = $this->record->merchant->profilePhoto
                ? [$this->record->merchant->profilePhoto->photo_url]
                : null;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Capture the processed logo path here — Filament has already moved the file
        // from livewire-tmp to public/merchants/logos by the time this runs.
        // We unset it so it never hits MerchantSetting::save() (no column in the table).
        if (array_key_exists('merchant_logo', $data)) {
            $path = collect($data['merchant_logo'])->filter()->first();
            if ($path) {
                $this->pendingLogoPath = $path;
            } else {
                $this->clearLogo = true;
            }
            unset($data['merchant_logo']);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $merchant = $this->record->merchant ?? auth('merchant')->user();
        if (! $merchant) {
            return;
        }

        /* ── MERCHANT LOGO ── */
        if ($this->pendingLogoPath) {
            $merchant->logo()?->delete();
            $merchant->logo()->create([
                'merchant_id' => $merchant->id,
                'type' => AttachmentType::IMAGE,
                'meta_type' => AttachmentMetaType::MERCHANT_LOGO,
                'photo_url' => $this->pendingLogoPath,
            ]);
        } elseif ($this->clearLogo) {
            $merchant->logo()?->delete();
        }

        /* ── CASH ACCOUNTS ── */
        $state = $this->form->getRawState();
        if (array_key_exists('cash_in_hand', $state) || array_key_exists('cash_in_bank', $state)) {
            $merchant->update([
                'cash_in_hand' => array_key_exists('cash_in_hand', $state)
                    ? $this->cashAccountAmount($state['cash_in_hand'])
                    : $merchant->cash_in_hand,
                'cash_in_bank' => array_key_exists('cash_in_bank', $state)
                    ? $this->cashAccountAmount($state['cash_in_bank'])
                    : $merchant->cash_in_bank,
            ]);

            app(FinanceLedger::class)->syncOpeningCash($merchant->fresh());
        }

        $this->redirect(request()->header('Referer'), navigate: false);
    }

    private function cashAccountAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
