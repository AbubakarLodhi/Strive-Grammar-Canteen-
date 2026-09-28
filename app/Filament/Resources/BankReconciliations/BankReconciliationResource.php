<?php

namespace App\Filament\Resources\BankReconciliations;

use App\Filament\Resources\BankReconciliations\Pages\CreateBankReconciliation;
use App\Filament\Resources\BankReconciliations\Pages\ListBankReconciliations;
use App\Filament\Resources\BankReconciliations\Pages\ViewBankReconciliation;
use App\Filament\Resources\BankReconciliations\Schemas\BankReconciliationForm;
use App\Filament\Resources\BankReconciliations\Tables\BankReconciliationsTable;
use App\Models\BankReconciliation;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BankReconciliationResource extends Resource
{
    protected static ?string $model = BankReconciliation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Scale;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Bank Reconciliation';

    protected static ?string $modelLabel = 'Bank Reconciliation';

    protected static ?string $pluralModelLabel = 'Bank Reconciliations';

    protected static ?string $recordTitleAttribute = 'recon_no';

    public static function canViewAny(): bool
    {
        return FinanceAccess::can('bank_reconciliations');
    }

    public static function canCreate(): bool
    {
        return FinanceAccess::can('bank_reconciliations', 'create');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return FinanceAccess::can('bank_reconciliations', 'delete')
            && $record instanceof BankReconciliation
            && ! $record->isCompleted();
    }

    public static function getEloquentQuery(): Builder
    {
        return FinanceAccess::scopeMerchant(parent::getEloquentQuery())
            ->with(['bankAccount']);
    }

    public static function form(Schema $schema): Schema
    {
        return BankReconciliationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankReconciliationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankReconciliations::route('/'),
            'create' => CreateBankReconciliation::route('/create'),
            'view' => ViewBankReconciliation::route('/{record}'),
        ];
    }
}
