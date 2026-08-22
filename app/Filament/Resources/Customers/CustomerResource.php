<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomerSales;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\PermissionModule;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Users;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'Customer';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();
        $guard = Filament::getCurrentPanel()->getAuthGuard();

        if (! $user) {
            return false;
        }

        // 🔐 Module gate
        if (! PermissionModule::isEnabledForCurrentMerchant('customers')) {
            return false;
        }

        // 🔐 Permission gate
        return $user->hasPermissionTo('customers.view', $guard);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Filament::auth()->user();
        $query = parent::getEloquentQuery();

        return static::scopeVisibleCustomers($query, $user);
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'sales' => ViewCustomerSales::route('/{record}/sales'),
            'edit' => EditCustomer::route('/{record}/edit'),
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

        // All staff see all merchant branches (no per-user branch restriction)
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

    public static function scopeVisibleCustomers(
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

        // Both merchant and staff see all customers for their merchant.
        // If a specific branch filter is passed, honour it regardless of user type.
        if ($branchIds->isNotEmpty()) {
            $query->whereHas('branches', fn (Builder $query) => $query->whereIn('branches.id', $branchIds));
        }

        return $query;
    }

    public static function syncCustomerBranches(
        Customer $customer,
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
            ->where('merchant_id', $customer->merchant_id)
            ->whereIn('id', $allowedBranchIds)
            ->get(['id', 'business_id']);

        $customer->businesses()->sync(
            $branchRows
                ->pluck('business_id')
                ->unique()
                ->values()
                ->mapWithKeys(fn (string $businessId) => [
                    $businessId => ['id' => (string) Str::uuid()],
                ])
                ->all()
        );

        $customer->branches()->sync(
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
