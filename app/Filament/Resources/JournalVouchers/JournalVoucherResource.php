<?php

namespace App\Filament\Resources\JournalVouchers;

use App\Filament\Resources\JournalVouchers\Pages\CreateJournalVoucher;
use App\Filament\Resources\JournalVouchers\Pages\EditJournalVoucher;
use App\Filament\Resources\JournalVouchers\Pages\ListJournalVouchers;
use App\Filament\Resources\JournalVouchers\Pages\ViewJournalVoucher;
use App\Filament\Resources\JournalVouchers\Schemas\JournalVoucherForm;
use App\Filament\Resources\JournalVouchers\Schemas\JournalVoucherInfolist;
use App\Filament\Resources\JournalVouchers\Tables\JournalVouchersTable;
use App\Models\JournalVoucher;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class JournalVoucherResource extends Resource
{
    protected static ?string $model = JournalVoucher::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Journal Vouchers';

    protected static ?string $recordTitleAttribute = 'voucher_no';

    public static function canViewAny(): bool
    {
        return FinanceAccess::can('journal_vouchers');
    }

    public static function canCreate(): bool
    {
        return FinanceAccess::can('journal_vouchers', 'create');
    }

    public static function canEdit(Model $record): bool
    {
        return FinanceAccess::can('journal_vouchers', 'update') && ! $record->isPosted();
    }

    public static function canDelete(Model $record): bool
    {
        return FinanceAccess::can('journal_vouchers', 'delete') && ! $record->isPosted();
    }

    public static function getEloquentQuery(): Builder
    {
        return FinanceAccess::scopeMerchant(parent::getEloquentQuery())->with(['lines.ledgerAccount']);
    }

    public static function form(Schema $schema): Schema
    {
        return JournalVoucherForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return JournalVoucherInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JournalVouchersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJournalVouchers::route('/'),
            'create' => CreateJournalVoucher::route('/create'),
            'view' => ViewJournalVoucher::route('/{record}'),
            'edit' => EditJournalVoucher::route('/{record}/edit'),
        ];
    }
}
