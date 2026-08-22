<?php

namespace App\Filament\Resources\LedgerAccounts;

use App\Filament\Resources\LedgerAccounts\Pages\CreateLedgerAccount;
use App\Filament\Resources\LedgerAccounts\Pages\EditLedgerAccount;
use App\Filament\Resources\LedgerAccounts\Pages\ListLedgerAccounts;
use App\Filament\Resources\LedgerAccounts\Schemas\LedgerAccountForm;
use App\Filament\Resources\LedgerAccounts\Tables\LedgerAccountsTable;
use App\Models\LedgerAccount;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LedgerAccountResource extends Resource
{
    protected static ?string $model = LedgerAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Chart of Accounts';

    protected static ?string $modelLabel = 'Ledger Account';

    protected static ?string $pluralModelLabel = 'Chart of Accounts';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return FinanceAccess::can('ledger_accounts');
    }

    public static function canCreate(): bool
    {
        return FinanceAccess::can('ledger_accounts', 'create');
    }

    public static function canEdit(Model $record): bool
    {
        return FinanceAccess::can('ledger_accounts', 'update');
    }

    public static function canDelete(Model $record): bool
    {
        return FinanceAccess::can('ledger_accounts', 'delete')
            && ! $record->is_system;
    }

    public static function getEloquentQuery(): Builder
    {
        return FinanceAccess::scopeMerchant(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return LedgerAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LedgerAccountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerAccounts::route('/'),
            'create' => CreateLedgerAccount::route('/create'),
            'edit' => EditLedgerAccount::route('/{record}/edit'),
        ];
    }
}
