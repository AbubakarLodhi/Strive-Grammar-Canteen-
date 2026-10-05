<?php

namespace App\Filament\Resources\Merchants\Schemas;

use App\Models\Merchant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MerchantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('phone')
                    ->tel()
                    ->nullable()
                    ->helperText('Optional')
                    ->maxLength(50)
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : null),
                TextInput::make('password')
                    ->password()
                    ->required()
                    ->hiddenOn('edit'),
                TextInput::make('email')
                    ->email()
                    ->required(),

                TextInput::make('address_line_1')
                    ->required(),
                TextInput::make('address_line_2'),
                TextInput::make('city')
                    ->required(),
                TextInput::make('social_media_handles'),
                TextInput::make('website')
                    ->url(),

                Section::make('Role & Status')
                    ->schema([
                        Select::make('roles')
                            ->label('Roles')
                            ->multiple()
                            ->relationship('roles', 'name', fn ($query) => $query->where('guard_name', 'merchant'))
                            ->preload()
                            ->required(),

                        Select::make('status')
                            ->options([
                                Merchant::STATUS_PENDING => 'Pending',
                                Merchant::STATUS_VERIFIED => 'Verified',
                                Merchant::STATUS_REJECTED => 'Rejected',
                            ])
                            ->required()
                            ->default('pending'),
                    ])
                    ->columnSpanFull()
                    ->columns(2),

                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
