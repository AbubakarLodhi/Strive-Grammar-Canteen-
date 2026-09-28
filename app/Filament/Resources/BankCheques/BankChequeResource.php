<?php

namespace App\Filament\Resources\BankCheques;

use App\Enums\ChequeStatus;
use App\Filament\Resources\BankCheques\Pages\CreateBankCheque;
use App\Filament\Resources\BankCheques\Pages\EditBankCheque;
use App\Filament\Resources\BankCheques\Pages\ListBankCheques;
use App\Filament\Resources\BankCheques\Pages\ViewBankCheque;
use App\Filament\Resources\BankCheques\Schemas\BankChequeForm;
use App\Filament\Resources\BankCheques\Schemas\BankChequeInfolist;
use App\Filament\Resources\BankCheques\Tables\BankChequesTable;
use App\Models\BankCheque;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BankChequeResource extends Resource
{
    protected static ?string $model = BankCheque::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Bank Cheques';

    protected static ?string $modelLabel = 'Bank Cheque';

    protected static ?string $pluralModelLabel = 'Bank Cheques';

    protected static ?string $recordTitleAttribute = 'cheque_number';

    public static function canViewAny(): bool
    {
        return FinanceAccess::can('bank_cheques');
    }

    public static function canCreate(): bool
    {
        return FinanceAccess::can('bank_cheques', 'create');
    }

    public static function canEdit(Model $record): bool
    {
        return FinanceAccess::can('bank_cheques', 'update')
            && $record instanceof BankCheque
            && $record->status === ChequeStatus::Pending;
    }

    public static function canDelete(Model $record): bool
    {
        return FinanceAccess::can('bank_cheques', 'delete')
            && $record instanceof BankCheque
            && $record->status === ChequeStatus::Pending;
    }

    public static function getEloquentQuery(): Builder
    {
        return FinanceAccess::scopeMerchant(parent::getEloquentQuery())
            ->with(['bankAccount', 'chequeBook', 'counterAccount', 'customer', 'vendor']);
    }

    public static function form(Schema $schema): Schema
    {
        return BankChequeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BankChequeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankChequesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankCheques::route('/'),
            'create' => CreateBankCheque::route('/create'),
            'view' => ViewBankCheque::route('/{record}'),
            'edit' => EditBankCheque::route('/{record}/edit'),
        ];
    }
}
