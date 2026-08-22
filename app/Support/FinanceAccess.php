<?php

namespace App\Support;

use App\Models\Merchant;
use App\Models\PermissionModule;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

class FinanceAccess
{
    public static function merchantId(): ?string
    {
        $user = Filament::auth()->user();

        return match (true) {
            $user instanceof Merchant => $user->id,
            $user instanceof User => $user->merchant_id,
            default => null,
        };
    }

    public static function can(string $module, string $action = 'view'): bool
    {
        $user = Filament::auth()->user();
        $guard = Filament::getCurrentPanel()?->getAuthGuard();

        if (! $user || ! $guard) {
            return false;
        }

        if (! PermissionModule::isEnabledForCurrentMerchant($module)) {
            return false;
        }

        return $user->hasPermissionTo("{$module}.{$action}", $guard);
    }

    public static function scopeMerchant(Builder $query, string $column = 'merchant_id'): Builder
    {
        $merchantId = self::merchantId();

        if (! $merchantId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($column, $merchantId);
    }

    public static function createdBy(): ?string
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user->id : null;
    }
}
