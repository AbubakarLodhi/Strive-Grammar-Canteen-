<?php

namespace App\Models;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use OwenIt\Auditing\Contracts\Auditable;

class PermissionModule extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'id',
        'module',
        'label',
        //        'is_enabled',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    public function merchants()
    {
        return $this->belongsToMany(
            Merchant::class,
            'merchant_permission_modules'
        )->withTimestamps();
    }

    public static function cacheKeyForMerchant(string $merchantId): string
    {
        return 'merchant_permission_modules:'.$merchantId;
    }

    public static function forgetMerchantCache(string $merchantId): void
    {
        Cache::forget(self::cacheKeyForMerchant($merchantId));
    }

    /**
     * @return list<string>
     */
    public static function enabledModulesForMerchant(string $merchantId): array
    {
        return Cache::remember(self::cacheKeyForMerchant($merchantId), now()->addMinutes(30), function () use ($merchantId): array {
            return self::query()
                ->whereHas('merchants', fn ($query) => $query->where('merchant_id', $merchantId))
                ->pluck('module')
                ->map(fn ($module): string => (string) $module)
                ->values()
                ->all();
        });
    }

    public static function isEnabledForMerchant(string $module, string $merchantId): bool
    {
        return in_array($module, self::enabledModulesForMerchant($merchantId), true);
    }

    public static function enabledForCurrentMerchant(): array
    {
        $user = Filament::auth()->user();

        $merchantId = match (true) {
            $user instanceof Merchant => $user->id,
            $user instanceof User && $user->merchant_id => $user->merchant_id,
            default => null,
        };

        if (! $merchantId) {
            return [];
        }

        return self::enabledModulesForMerchant($merchantId);
    }

    public static function isEnabledForCurrentMerchant(string $module): bool
    {
        return in_array($module, self::enabledForCurrentMerchant(), true);
    }
}
