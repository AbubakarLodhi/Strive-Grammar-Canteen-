<?php

namespace App\Filament\Resources\CashVouchers;

use App\Filament\Resources\CashVouchers\Pages\CreateCashVoucher;
use App\Filament\Resources\CashVouchers\Pages\EditCashVoucher;
use App\Filament\Resources\CashVouchers\Pages\ListCashVouchers;
use App\Filament\Resources\CashVouchers\Pages\ViewCashVoucher;
use App\Filament\Resources\CashVouchers\Schemas\CashVoucherForm;
use App\Filament\Resources\CashVouchers\Schemas\CashVoucherInfolist;
use App\Filament\Resources\CashVouchers\Tables\CashVouchersTable;
use App\Models\CashVoucher;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CashVoucherResource extends Resource
{
    protected static ?string $model = CashVoucher::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Banknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 35;

    protected static ?string $navigationLabel = 'Cash Vouchers';

    protected static ?string $recordTitleAttribute = 'voucher_no';

    public static function canViewAny(): bool
    {
        return FinanceAccess::can('cash_vouchers');
    }

    public static function canCreate(): bool
    {
        return FinanceAccess::can('cash_vouchers', 'create');
    }

    public static function canEdit(Model $record): bool
    {
        return FinanceAccess::can('cash_vouchers', 'update')
            && $record instanceof CashVoucher
            && ! $record->isPosted();
    }

    public static function canDelete(Model $record): bool
    {
        return FinanceAccess::can('cash_vouchers', 'delete')
            && $record instanceof CashVoucher
            && ! $record->isPosted();
    }

    public static function getEloquentQuery(): Builder
    {
        return FinanceAccess::scopeMerchant(parent::getEloquentQuery())
            ->with(['cashAccount', 'counterAccount', 'journalVoucher']);
    }

    public static function form(Schema $schema): Schema
    {
        return CashVoucherForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CashVoucherInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CashVouchersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashVouchers::route('/'),
            'create' => CreateCashVoucher::route('/create'),
            'view' => ViewCashVoucher::route('/{record}'),
            'edit' => EditCashVoucher::route('/{record}/edit'),
        ];
    }
}
