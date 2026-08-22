<?php

namespace App\Filament\Resources\SaleReturns;

use App\Filament\Resources\SaleReturns\Pages\CreateSaleReturn;
use App\Filament\Resources\SaleReturns\Pages\EditSaleReturn;
use App\Filament\Resources\SaleReturns\Pages\ListSaleReturns;
use App\Filament\Resources\SaleReturns\Schemas\SaleReturnForm;
use App\Filament\Resources\SaleReturns\Tables\SaleReturnsTable;
use App\Models\PermissionModule;
use App\Models\SaleReturn;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SaleReturnResource extends Resource
{
    protected static ?string $model = SaleReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowUturnRight;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'Sale Return';

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();
        $guard = Filament::getCurrentPanel()->getAuthGuard();

        if (! $user) {
            return false;
        }
        // 🔐 Module gate
        if (! PermissionModule::isEnabledForCurrentMerchant('sales')) {
            return false;
        }

        // 🔐 Permission gate
        return $user->hasPermissionTo('sales.view', $guard);
    }

    public static function form(Schema $schema): Schema
    {
        return SaleReturnForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SaleReturnsTable::configure($table);
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
            'index' => ListSaleReturns::route('/'),
            // 'create' => CreateSaleReturn::route('/create'),
            // 'edit' => EditSaleReturn::route('/{record}/edit'),
        ];
    }
}
