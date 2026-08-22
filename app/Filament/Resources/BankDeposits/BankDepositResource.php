<?php

namespace App\Filament\Resources\BankDeposits;

use App\Filament\Resources\BankDeposits\Pages\CreateBankDeposit;
use App\Filament\Resources\BankDeposits\Pages\EditBankDeposit;
use App\Filament\Resources\BankDeposits\Pages\ListBankDeposits;
use App\Filament\Resources\BankDeposits\Pages\ViewBankDeposit;
use App\Filament\Resources\BankDeposits\Schemas\BankDepositForm;
use App\Filament\Resources\BankDeposits\Schemas\BankDepositInfolist;
use App\Filament\Resources\BankDeposits\Tables\BankDepositsTable;
use App\Models\BankDeposit;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BankDepositResource extends Resource
{
    protected static ?string $model = BankDeposit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BuildingLibrary;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Bank Deposits';

    protected static ?string $recordTitleAttribute = 'deposit_no';

    public static function canViewAny(): bool
    {
        return FinanceAccess::can('bank_deposits');
    }

    public static function canCreate(): bool
    {
        return FinanceAccess::can('bank_deposits', 'create');
    }

    public static function canEdit(Model $record): bool
    {
        return FinanceAccess::can('bank_deposits', 'update') && ! $record->isPosted();
    }

    public static function canDelete(Model $record): bool
    {
        return FinanceAccess::can('bank_deposits', 'delete') && ! $record->isPosted();
    }

    public static function getEloquentQuery(): Builder
    {
        return FinanceAccess::scopeMerchant(parent::getEloquentQuery())
            ->with(['bankAccount', 'sourceAccount', 'journalVoucher']);
    }

    public static function form(Schema $schema): Schema
    {
        return BankDepositForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BankDepositInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankDepositsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankDeposits::route('/'),
            'create' => CreateBankDeposit::route('/create'),
            'view' => ViewBankDeposit::route('/{record}'),
            'edit' => EditBankDeposit::route('/{record}/edit'),
        ];
    }
}
