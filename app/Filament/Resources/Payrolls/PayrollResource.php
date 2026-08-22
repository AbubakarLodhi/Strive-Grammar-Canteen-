<?php

namespace App\Filament\Resources\Payrolls;

use App\Filament\Resources\Payrolls\Pages\CreatePayroll;
use App\Filament\Resources\Payrolls\Pages\EditPayroll;
use App\Filament\Resources\Payrolls\Pages\ListPayrolls;
use App\Filament\Resources\Payrolls\Pages\ViewPayroll;
use App\Filament\Resources\Payrolls\Schemas\PayrollForm;
use App\Filament\Resources\Payrolls\Schemas\PayrollInfolist;
use App\Filament\Resources\Payrolls\Tables\PayrollsTable;
use App\Models\Merchant;
use App\Models\Payroll;
use App\Models\PermissionModule;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PayrollResource extends Resource
{
    protected static ?string $model = Payroll::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CurrencyDollar;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'payroll_no';

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();
        $guard = Filament::getCurrentPanel()->getAuthGuard();

        if (! $user) {
            return false;
        }
        // 🔐 Module gate
        if (! PermissionModule::isEnabledForCurrentMerchant('payrolls')) {
            return false;
        }

        // 🔐 Permission gate
        return $user->hasPermissionTo('payrolls.view', $guard);
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        return $user->hasPermissionTo(
            'payrolls.create',
            Filament::getCurrentPanel()->getAuthGuard()
        );
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        return $user->hasPermissionTo(
            'payrolls.update',
            Filament::getCurrentPanel()->getAuthGuard()
        );
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        return $user->hasPermissionTo(
            'payrolls.delete',
            Filament::getCurrentPanel()->getAuthGuard()
        );
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Filament::auth()->user();
        $query = parent::getEloquentQuery();

        // Merchants see all payrolls for their staff
        if ($user instanceof Merchant) {
            return $query->where('merchant_id', $user->id);
        }

        // Staff members: if they have permission to view all, show all from their merchant
        // Otherwise, show only their own payrolls
        if ($user->hasPermissionTo('payrolls.view', Filament::getCurrentPanel()->getAuthGuard())) {
            return $query->where('merchant_id', $user->merchant_id);
        }

        return $query->where('user_id', $user->id);
    }

    public static function form(Schema $schema): Schema
    {
        return PayrollForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PayrollInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayrollsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrolls::route('/'),
            'create' => CreatePayroll::route('/create'),
            'view' => ViewPayroll::route('/{record}'),
            'edit' => EditPayroll::route('/{record}/edit'),
        ];
    }
}
