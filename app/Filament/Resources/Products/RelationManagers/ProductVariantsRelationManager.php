<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductVariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Variant Name')
                ->helperText('Optional (e.g. 72V / 30Ah)')
                ->maxLength(255),

            TextInput::make('sku')
                ->required()
                ->maxLength(255),

            TextInput::make('purchase_price')
                ->numeric()
                ->nullable(),

            TextInput::make('selling_price')
                ->numeric()
                ->nullable(),

            Hidden::make('merchant_id')
                ->default(fn ($livewire) => $livewire->ownerRecord->merchant_id),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('sku')->searchable(),
                TextColumn::make('selling_price')->money('PKR')->sortable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->color(fn ($state) => $state ? 'primary' : 'danger'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make()
                    ->color('warning'),
                DeleteAction::make()
                    ->color('danger'),
            ]);
    }

    // ProductVariantsRelationManager.php

    public static function getRelations(): array
    {
        return [
            ProductVariantValuesRelationManager::class,
        ];
    }
}
