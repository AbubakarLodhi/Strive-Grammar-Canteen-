<?php

namespace App\Filament\Resources\ChequeBooks;

use App\Filament\Resources\ChequeBooks\Pages\CreateChequeBook;
use App\Filament\Resources\ChequeBooks\Pages\EditChequeBook;
use App\Filament\Resources\ChequeBooks\Pages\ListChequeBooks;
use App\Filament\Resources\ChequeBooks\Schemas\ChequeBookForm;
use App\Filament\Resources\ChequeBooks\Tables\ChequeBooksTable;
use App\Models\ChequeBook;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ChequeBookResource extends Resource
{
    protected static ?string $model = ChequeBook::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Cheque Books';

    protected static ?string $modelLabel = 'Cheque Book';

    protected static ?string $pluralModelLabel = 'Cheque Books';

    protected static ?string $recordTitleAttribute = 'book_no';

    public static function canViewAny(): bool
    {
        return FinanceAccess::can('cheque_books');
    }

    public static function canCreate(): bool
    {
        return FinanceAccess::can('cheque_books', 'create');
    }

    public static function canEdit(Model $record): bool
    {
        return FinanceAccess::can('cheque_books', 'update');
    }

    public static function canDelete(Model $record): bool
    {
        return FinanceAccess::can('cheque_books', 'delete')
            && ! $record->cheques()->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        return FinanceAccess::scopeMerchant(parent::getEloquentQuery())
            ->with(['bankAccount']);
    }

    public static function form(Schema $schema): Schema
    {
        return ChequeBookForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChequeBooksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChequeBooks::route('/'),
            'create' => CreateChequeBook::route('/create'),
            'edit' => EditChequeBook::route('/{record}/edit'),
        ];
    }
}
