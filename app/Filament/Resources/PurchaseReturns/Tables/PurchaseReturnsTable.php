<?php

namespace App\Filament\Resources\PurchaseReturns\Tables;

use App\Filament\Support\DateRangeFilter;
use App\Services\PurchaseReturnService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class PurchaseReturnsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                //                TextColumn::make('id')
                //                    ->label('ID'),
                TextColumn::make('merchant.name')
                    ->label('Merchant')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('purchase.purchase_no')
                    ->label('Purchase No.')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('return_no')
                    ->searchable(),
                TextColumn::make('return_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('subtotal')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_discount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_tax')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                DateRangeFilter::make('return_date_range', 'return_date', 'Return Date'),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->recordActions([
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->action(function ($record): void {
                        PurchaseReturnService::deleteReturn($record);
                        Notification::make()
                            ->success()
                            ->title('Purchase return deleted')
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth(Filament::getCurrentPanel()->getAuthGuard())
                            ->user()?->hasPermissionTo('purchases.delete', Filament::getCurrentPanel()->getAuthGuard())
                        )
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                PurchaseReturnService::deleteReturn($record);
                            }

                            Notification::make()
                                ->success()
                                ->title('Purchase returns deleted')
                                ->send();
                        }),
                ]),
            ]);
    }
}
