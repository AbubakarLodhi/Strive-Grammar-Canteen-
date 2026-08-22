<?php

namespace App\Filament\Resources\MerchantSettings\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MerchantSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            FileUpload::make('merchant_logo')
                ->label('Merchant Logo')
                ->image()
                ->disk('public')
                ->directory('merchants/logos')
                ->imagePreviewHeight(120),

            //            FileUpload::make('profile_photo')
            //                ->label('Profile Photo')
            //                ->image()
            //                ->disk('public')
            //                ->directory('merchants/profile-photos')
            //                ->imagePreviewHeight(120),

            /* ================= LIGHT MODE COLORS ================= */
            Section::make('Light Mode Colors')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    ColorPicker::make('primary_color')
                        ->label('Primary')
                        ->default('#1B4F72')
                        ->required(),

                    ColorPicker::make('secondary_color')
                        ->label('Secondary')
                        ->default('#C4A35A')
                        ->required(),

                    ColorPicker::make('warning_color')
                        ->label('Warning')
                        ->default('#FACC15')
                        ->required(),

                    ColorPicker::make('danger_color')
                        ->label('Danger')
                        ->default('#DC2626')
                        ->required(),

                    ColorPicker::make('success_color')
                        ->label('Success')
                        ->default('#22C55E')
                        ->required(),

                    ColorPicker::make('default_color')
                        ->label('Default')
                        ->default('#E5E7EB')
                        ->required(),
                ]),

            Section::make('Cash Accounts')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('cash_in_hand')
                        ->label('Cash In Hand')
                        ->prefix('PKR')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->step(0.01)
                        ->dehydrated(false),

                    TextInput::make('cash_in_bank')
                        ->label('Opening UBL Bank')
                        ->prefix('PKR')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->step(0.01)
                        ->dehydrated(false),
                ]),

            /* ================= MERCHANT ================= */
            Hidden::make('merchant_id')
                ->default(fn () => auth('merchant')->id())
                ->required(),
        ])->columns(1);
    }
}
