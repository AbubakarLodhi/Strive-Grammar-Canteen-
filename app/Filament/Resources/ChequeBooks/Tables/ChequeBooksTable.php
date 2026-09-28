<?php

namespace App\Filament\Resources\ChequeBooks\Tables;

use App\Models\ChequeBook;
use App\Support\FinanceAccess;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChequeBooksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('book_no')
                    ->label('Book')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bankAccount.name')
                    ->label('Bank')
                    ->formatStateUsing(fn ($state, ChequeBook $record): string => $record->bankAccount?->bankLabel() ?? (string) $state),
                TextColumn::make('start_number')
                    ->label('Start'),
                TextColumn::make('end_number')
                    ->label('End'),
                TextColumn::make('next_number')
                    ->label('Next'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make()
                    ->label('')
                    ->tooltip('Edit')
                    ->visible(fn (): bool => FinanceAccess::can('cheque_books', 'update')),
                DeleteAction::make()
                    ->label('')
                    ->tooltip('Delete')
                    ->visible(fn (ChequeBook $record): bool => FinanceAccess::can('cheque_books', 'delete')
                        && ! $record->cheques()->exists()),
            ]);
    }
}
