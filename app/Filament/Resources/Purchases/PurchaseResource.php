<?php

namespace App\Filament\Resources\Purchases;

use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\Pages\ViewPurchase;
use App\Filament\Resources\Purchases\Schemas\PurchaseForm;
use App\Filament\Resources\Purchases\Schemas\PurchaseInfolist;
use App\Filament\Resources\Purchases\Tables\PurchasesTable;
use App\Models\Merchant;
use App\Models\PermissionModule;
use App\Models\Purchase;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ShoppingCart;

    protected static string|\UnitEnum|null $navigationGroup = 'Purchase';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'purchase_no';

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();
        $guard = Filament::getCurrentPanel()->getAuthGuard();

        if (! $user) {
            return false;
        }
        // 🔐 Module gate
        if (! PermissionModule::isEnabledForCurrentMerchant('purchases')) {
            return false;
        }

        // 🔐 Permission gate
        return $user->hasPermissionTo('purchases.view', $guard);
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();
        $guard = Filament::getCurrentPanel()->getAuthGuard();

        if (! $user) {
            return false;
        }

        if (! $record instanceof Purchase) {
            return false;
        }

        if ($record->returns()->exists() && ! self::hasReturnableItems($record)) {
            return false;
        }

        return $user->hasPermissionTo('purchases.update', $guard);
    }

    private static function hasReturnableItems(Purchase $purchase): bool
    {
        foreach ($purchase->items as $item) {
            $itemQty = (int) ($item->quantity ?? 0);
            $variantQty = (int) $item->variants->sum('quantity');

            if (max($itemQty, $variantQty) > 0) {
                return true;
            }
        }

        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'returns:id,purchase_id,subtotal,total_discount,total_tax,total_amount',
            ]);
        $user = Filament::auth()->user();

        $merchantId = match (true) {
            $user instanceof Merchant => $user->id,
            $user instanceof User => $user->merchant_id,
            default => null,
        };

        if (! $merchantId) {
            return $query->whereRaw('1 = 0');
        }

        // 🟢 MERCHANT → all purchases
        if ($user instanceof Merchant) {
            return $query->where('merchant_id', $merchantId);
        }

        // 🔵 STAFF → via pivots (business_users + branch_users)
        return $query
            ->where('merchant_id', $merchantId)
            ->whereHas('items.business.users', fn ($q) => $q->where('users.id', $user->id)
            )
            ->whereHas('items.branch.users', fn ($q) => $q->where('users.id', $user->id)
            );

    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PurchaseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchasesTable::configure($table);
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
            'index' => ListPurchases::route('/'),
            'create' => CreatePurchase::route('/create'),
            'view' => ViewPurchase::route('/{record}'),
            'edit' => EditPurchase::route('/{record}/edit'),
        ];
    }
}
