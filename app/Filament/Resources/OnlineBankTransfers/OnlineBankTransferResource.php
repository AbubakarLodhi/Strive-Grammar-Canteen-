<?php

namespace App\Filament\Resources\OnlineBankTransfers;

use App\Filament\Resources\OnlineBankTransfers\Pages\CreateOnlineBankTransfer;
use App\Filament\Resources\OnlineBankTransfers\Pages\EditOnlineBankTransfer;
use App\Filament\Resources\OnlineBankTransfers\Pages\ListOnlineBankTransfers;
use App\Filament\Resources\OnlineBankTransfers\Pages\ViewOnlineBankTransfer;
use App\Filament\Resources\OnlineBankTransfers\Schemas\OnlineBankTransferForm;
use App\Filament\Resources\OnlineBankTransfers\Schemas\OnlineBankTransferInfolist;
use App\Filament\Resources\OnlineBankTransfers\Tables\OnlineBankTransfersTable;
use App\Models\OnlineBankTransfer;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OnlineBankTransferResource extends Resource
{
    protected static ?string $model = OnlineBankTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 36;

    protected static ?string $navigationLabel = 'Online Transfers';

    protected static ?string $recordTitleAttribute = 'transfer_no';

    public static function canViewAny(): bool
    {
        return FinanceAccess::can('online_transfers');
    }

    public static function canCreate(): bool
    {
        return FinanceAccess::can('online_transfers', 'create');
    }

    public static function canEdit(Model $record): bool
    {
        return FinanceAccess::can('online_transfers', 'update')
            && $record instanceof OnlineBankTransfer
            && ! $record->isPosted();
    }

    public static function canDelete(Model $record): bool
    {
        return FinanceAccess::can('online_transfers', 'delete')
            && $record instanceof OnlineBankTransfer
            && ! $record->isPosted();
    }

    public static function getEloquentQuery(): Builder
    {
        return FinanceAccess::scopeMerchant(parent::getEloquentQuery())
            ->with(['fromAccount', 'toAccount', 'journalVoucher']);
    }

    public static function form(Schema $schema): Schema
    {
        return OnlineBankTransferForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OnlineBankTransferInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OnlineBankTransfersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOnlineBankTransfers::route('/'),
            'create' => CreateOnlineBankTransfer::route('/create'),
            'view' => ViewOnlineBankTransfer::route('/{record}'),
            'edit' => EditOnlineBankTransfer::route('/{record}/edit'),
        ];
    }
}
