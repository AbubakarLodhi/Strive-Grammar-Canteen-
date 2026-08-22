<?php

namespace App\Filament\Resources\Vendors;

use App\Filament\Resources\Vendors\Pages\CreateVendor;
use App\Filament\Resources\Vendors\Pages\EditVendor;
use App\Filament\Resources\Vendors\Pages\ListVendors;
use App\Filament\Resources\Vendors\Pages\ViewVendorPurchases;
use App\Filament\Resources\Vendors\Schemas\VendorForm;
use App\Filament\Resources\Vendors\Tables\VendorsTable;
use App\Models\Branch;
use App\Models\Merchant;
use App\Models\PermissionModule;
use App\Models\User;
use App\Models\Vendor;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class VendorResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Users;

    protected static string|\UnitEnum|null $navigationGroup = 'Purchase';

    protected static ?string $recordTitleAttribute = 'Vendor';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();
        $guard = Filament::getCurrentPanel()->getAuthGuard();

        if (! $user) {
            return false;
        }

        if (! PermissionModule::isEnabledForCurrentMerchant('vendors')) {
            return false;
        }

        return $user->hasPermissionTo('vendors.view', $guard);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Filament::auth()->user();
        $query = parent::getEloquentQuery();

        return static::scopeVisibleVendors($query, $user);
    }

    public static function form(Schema $schema): Schema
    {
        return VendorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendorsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendors::route('/'),
            'create' => CreateVendor::route('/create'),
            'purchases' => ViewVendorPurchases::route('/{record}/purchases'),
            'edit' => EditVendor::route('/{record}/edit'),
        ];
    }

    public static function merchantIdFor(Merchant|User|null $user): ?string
    {
        return match (true) {
            $user instanceof Merchant => $user->id,
            $user instanceof User => $user->merchant_id,
            default => null,
        };
    }

    public static function accessibleBranchesQuery(Merchant|User|null $user): Builder
    {
        $merchantId = static::merchantIdFor($user);

        $query = Branch::query()
            ->withoutTrashed()
            ->when(
                filled($merchantId),
                fn (Builder $query) => $query->where('merchant_id', $merchantId),
                fn (Builder $query) => $query->whereRaw('1 = 0')
            );

        if ($user instanceof User) {
            $query
                ->whereHas('users', fn (Builder $query) => $query->where('users.id', $user->id))
                ->whereHas('business.users', fn (Builder $query) => $query->where('users.id', $user->id));
        }

        return $query;
    }

    public static function accessibleBranchIds(Merchant|User|null $user): Collection
    {
        return static::accessibleBranchesQuery($user)
            ->pluck('branches.id')
            ->values();
    }

    public static function branchOptions(Merchant|User|null $user, ?array $limitToBranchIds = null): array
    {
        $allowedBranchIds = static::normalizeIds($limitToBranchIds);

        $query = static::accessibleBranchesQuery($user)
            ->with('business')
            ->orderBy('business_id')
            ->orderBy('branches.name');

        if ($allowedBranchIds->isNotEmpty()) {
            $query->whereIn('branches.id', $allowedBranchIds);
        }

        return $query->get()
            ->groupBy(fn (Branch $branch) => $branch->business?->name ?? 'Other')
            ->map(fn (Collection $group) => $group->pluck('name', 'id')->toArray())
            ->toArray();
    }

    public static function scopeVisibleVendors(
        Builder $query,
        Merchant|User|null $user,
        ?array $limitToBranchIds = null,
    ): Builder {
        $merchantId = static::merchantIdFor($user);

        if (! filled($merchantId)) {
            return $query->whereRaw('1 = 0');
        }

        $query
            ->withoutTrashed()
            ->where('merchant_id', $merchantId);

        $branchIds = static::normalizeIds($limitToBranchIds);

        if ($user instanceof Merchant) {
            if ($branchIds->isNotEmpty()) {
                $query->whereHas('branches', fn (Builder $query) => $query->whereIn('branches.id', $branchIds));
            }

            return $query;
        }

        $staffBusinessIds = $user->businesses()->pluck('businesses.id')->values();
        $staffBranchIds = $user->branches()->pluck('branches.id')->values();

        if ($staffBusinessIds->isEmpty() || $staffBranchIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        if ($branchIds->isNotEmpty()) {
            $staffBranchIds = $staffBranchIds
                ->intersect($branchIds)
                ->values();
        }

        if ($staffBranchIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereHas('businesses', fn (Builder $query) => $query->whereIn('businesses.id', $staffBusinessIds))
            ->whereHas('branches', fn (Builder $query) => $query->whereIn('branches.id', $staffBranchIds));
    }

    public static function syncVendorBranches(
        Vendor $vendor,
        array $branchIds,
        Merchant|User|null $user = null,
    ): void {
        $allowedBranchIds = static::normalizeIds($branchIds);

        if ($user) {
            $allowedBranchIds = static::accessibleBranchIds($user)
                ->intersect($allowedBranchIds)
                ->values();
        }

        $branchRows = Branch::query()
            ->withoutTrashed()
            ->where('merchant_id', $vendor->merchant_id)
            ->whereIn('id', $allowedBranchIds)
            ->get(['id', 'business_id']);

        $vendor->businesses()->sync(
            $branchRows
                ->pluck('business_id')
                ->unique()
                ->values()
                ->mapWithKeys(fn (string $businessId) => [
                    $businessId => ['id' => (string) Str::uuid()],
                ])
                ->all()
        );

        $vendor->branches()->sync(
            $branchRows
                ->mapWithKeys(fn (Branch $branch) => [
                    $branch->id => [
                        'id' => (string) Str::uuid(),
                        'business_id' => $branch->business_id,
                    ],
                ])
                ->all()
        );
    }

    protected static function normalizeIds(?array $ids): Collection
    {
        return collect($ids)
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();
    }
}
