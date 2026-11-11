<?php

//
// namespace App\Filament\Resources\Products\Schemas;
//
// use Filament\Facades\Filament;
// use Filament\Forms\Components\Hidden;
// use Filament\Forms\Components\Select;
// use Filament\Forms\Components\TextInput;
// use Filament\Forms\Components\Textarea;
// use Filament\Forms\Components\Toggle;
// use Filament\Schemas\Schema;
//
// class ProductForm
// {
//    public static function configure(Schema $schema): Schema
//    {
//        return $schema
//            ->components([
//
//                Select::make('category_id')
//                    ->relationship('category', 'name')
//                    ->searchable()
//                    ->preload()
//                    ->nullable(),
//
//                Select::make('sub_category_id')
//                    ->relationship('subCategory', 'name')
//                    ->searchable()
//                    ->preload()
//                    ->nullable(),
//                /* -------------------------
//                 | Core Info
//                 |--------------------------*/
//                TextInput::make('name')
//                    ->required()
//                    ->maxLength(255),
//
// //                TextInput::make('sku')
// //                    ->required()
// //                    ->maxLength(255),
//
//                Textarea::make('description')
//                    ->columnSpanFull(),
//
//                /* -------------------------
//                 | Product Behaviour
//                 |--------------------------*/
//                Select::make('type')
//                    ->required()
//                    ->options([
//                        'stock'          => 'Stock Product',
//                        'service'        => 'Service',
//                        'measured_stock' => 'Measured Stock (Fuel)',
//                        'custom'         => 'Custom Item',
//                    ])
//                    ->reactive(),
//
//                Select::make('unit')
//                    ->required()
//                    ->options([
//                        'pieces'   => 'Pieces',
//                        'liter' => 'Liter',
//                        'gram'  => 'Gram',
//                        'kg'    => 'Kilogram',
//                        'job'   => 'Job',
//                        'hour'  => 'Hour',
//                        'day'   => 'Day',
//                        'sqm'   => 'Square Meter',
//                        'set'   => 'Set',
//                    ]),

//                    TextInput::make('purchase_price')
//    ->label('Purchase Price')
//    ->numeric()
//    ->prefix('PKR')
//    ->nullable(),

// TextInput::make('selling_price')
//    ->label('Selling Price')
//    ->numeric()
//    ->prefix('PKR')
//    ->nullable(),
//
//
//                /* -------------------------
//                 | Pricing
//                 |--------------------------*/
// //                TextInput::make('purchase_price')
// //                    ->numeric()
// //                    ->prefix('$')
// //                    ->visible(fn ($get) => $get('type') === 'stock'),
// //
// //                TextInput::make('selling_price')
// //                    ->numeric()
// //                    ->prefix('$')
// //                    ->visible(fn ($get) => ! $get('is_variable_price')),
//
//                /* -------------------------
//                 | Relationships
//                 |--------------------------*/
//                Hidden::make('merchant_id')
//                    ->default(fn () => Filament::auth()->user()->id)
//                    ->dehydrated()
//                    ->required(),
//
//
//                Select::make('business_id')
//                    ->relationship('business', 'name')
//                    ->searchable()
//                    ->preload()
//                    ->required(),
//
//                Select::make('brand_id')
//                    ->relationship('brand', 'name')
//                    ->searchable()
//                    ->preload()
//                    ->nullable(),
//
//                Select::make('brand_model_id')
//                    ->relationship('brandModel', 'name')
//                    ->searchable()
//                    ->preload()
//                    ->nullable(),
//
//                Toggle::make('track_inventory')
//                    ->label('Track Inventory')
//                    ->default(true),
//
//                Toggle::make('is_variable_price')
//                    ->label('Variable / Runtime Pricing')
//                    ->default(false),
//                /* -------------------------
//                 | Status
//                 |--------------------------*/
//                Toggle::make('is_active')
//                    ->required(),
//            ]);
//    }
// }

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Branch;
use App\Models\Merchant;
use App\Models\User;
use App\Support\ProductUnit;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Availability')
                ->description('Select businesses and specific branches where this product will be available.')
                ->columnSpanFull()
                ->columns(2)
                ->schema([

                    /* =========================
                     | BUSINESS SELECT
                     |=========================*/
                    //                    Select::make('businesses')
                    //                        ->label('Businesses')
                    //                        ->relationship(
                    //                            name: 'businesses',
                    //                            titleAttribute: 'name',
                    //                            modifyQueryUsing: function (Builder $query) {
                    //                                $user = Filament::auth()->user();
                    //                                $query->where('status', true);
                    //                                $merchantId = match (true) {
                    //                                    $user instanceof \App\Models\Merchant => $user->id,
                    //                                    $user instanceof \App\Models\User     => $user->merchant_id,
                    //                                    default                               => null,
                    //                                };
                    //
                    //                                if ($merchantId) {
                    //                                    $query->where('merchant_id', $merchantId);
                    //                                }
                    //                            }
                    //                        )
                    //                        ->multiple()
                    //                        ->searchable()
                    //                        ->preload()
                    //                        ->required()
                    //                        ->reactive()
                    //                        ->live()
                    //                        ->afterStateUpdated(function ($state, callable $set, callable $get, $livewire) {
                    //                            $livewire->resetValidation('data.businesses');
                    //                             $livewire->resetErrorBag('data.businesses');
                    //                }),

                    /* =========================
                     | BRANCH SELECT
                     |=========================*/
                    Select::make('branches')
                        ->label('Branches')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required()
                        ->allowHtml() // ✅ REQUIRED for indentation
                        ->options(function () {
                            $user = Filament::auth()->user();

                            $branchesQuery = Branch::query()
                                ->withoutTrashed()
                                ->with('business')
                                ->where('is_active', true);

                            // Merchant → all branches
                            if ($user instanceof Merchant) {
                                $branchesQuery->where('merchant_id', $user->id);
                            }
                            // Staff → only assigned branches
                            else {
                                $branchesQuery->whereIn(
                                    'branches.id',
                                    $user->branches()->pluck('branches.id')
                                );
                            }

                            return $branchesQuery
                                ->orderBy('business_id')
                                ->orderBy('name')
                                ->get()
                                ->groupBy(fn ($branch) => $branch->business?->name ?? 'Other')
                                ->map(fn ($group) => $group->pluck('name', 'id')
                                    ->map(fn ($name) => '&nbsp;&nbsp;&nbsp;&nbsp;'.e($name)) // 👈 indent
                                    ->toArray()
                                )
                                ->toArray();
                        })
                        ->getOptionLabelUsing(
                            fn ($value) => Branch::find($value)?->name
                        ),

                ]),

            Section::make('Classification')
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    Select::make('category_id')
                        ->relationship(
                            name: 'category',
                            titleAttribute: 'name',
                            modifyQueryUsing: function (Builder $query) {
                                $user = Filament::auth()->user();

                                $merchantId = match (true) {
                                    $user instanceof Merchant => $user->id,
                                    $user instanceof User => $user->merchant_id,
                                    default => null,
                                };

                                $query->whereNull('parent_id');

                                if ($merchantId) {
                                    $query->where('merchant_id', $merchantId);
                                }
                            }
                        )
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Select::make('sub_category_id')
                        ->relationship(
                            name: 'subCategory',
                            titleAttribute: 'name',
                            modifyQueryUsing: function (Builder $query, callable $get) {
                                $user = Filament::auth()->user();

                                $merchantId = match (true) {
                                    $user instanceof Merchant => $user->id,
                                    $user instanceof User => $user->merchant_id,
                                    default => null,
                                };

                                if ($merchantId) {
                                    $query->where('merchant_id', $merchantId);
                                }

                                if ($categoryId = $get('category_id')) {
                                    $query->where('parent_id', $categoryId);
                                } else {
                                    $query->whereRaw('1 = 0');
                                }
                            }
                        )
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Select::make('brand_id')
                        ->label('Brand')
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->relationship(
                            name: 'brand',
                            titleAttribute: 'name',
                            modifyQueryUsing: function (Builder $query, callable $get) {
                                $user = Filament::auth()->user();

                                $merchantId = match (true) {
                                    $user instanceof Merchant => $user->id,
                                    $user instanceof User => $user->merchant_id,
                                    default => null,
                                };

                                // ❌ No merchant → no brands
                                if (! $merchantId) {
                                    $query->whereRaw('1 = 0');

                                    return;
                                }

                                $query->where('merchant_id', $merchantId);

                                $subCategoryId = $get('sub_category_id');

                                // ❌ No sub-category → show nothing
                                if (! $subCategoryId) {
                                    $query->whereRaw('1 = 0');

                                    return;
                                }

                                // ✅ Only brands linked to selected sub-category
                                $query->whereExists(function ($sub) use ($subCategoryId) {
                                    $sub->selectRaw(1)
                                        ->from('brand_category')
                                        ->whereColumn('brand_category.brand_id', 'brands.id')
                                        ->where('brand_category.category_id', $subCategoryId);
                                });
                            }
                        ),

                    Select::make('brand_model_id')
                        ->label('Brand Model')
                        ->searchable()
                        ->preload()
                        ->reactive()
                        ->nullable()
                        ->relationship(
                            name: 'brandModel',
                            titleAttribute: 'name',
                            modifyQueryUsing: function (Builder $query, callable $get) {
                                $user = Filament::auth()->user();

                                $merchantId = match (true) {
                                    $user instanceof Merchant => $user->id,
                                    $user instanceof User => $user->merchant_id,
                                    default => null,
                                };

                                // ❌ No merchant → no models
                                if (! $merchantId) {
                                    $query->whereRaw('1 = 0');

                                    return;
                                }

                                $query->where('merchant_id', $merchantId);

                                $brandId = $get('brand_id');
                                $subCategoryId = $get('sub_category_id');

                                // ❌ No brand or no sub-category → show nothing
                                if (! $brandId || ! $subCategoryId) {
                                    $query->whereRaw('1 = 0');

                                    return;
                                }

                                // ✅ Must belong to selected brand
                                $query->where('brand_id', $brandId);

                                // ✅ Ensure brand ↔ sub-category relation exists
                                $query->whereExists(function ($sub) use ($subCategoryId) {
                                    $sub->selectRaw(1)
                                        ->from('brand_category')
                                        ->whereColumn('brand_category.brand_id', 'brand_models.brand_id')
                                        ->where('brand_category.category_id', $subCategoryId);
                                });
                            }
                        ),

                ]),

            /* =========================
             | PRODUCT
             |=========================*/
            Section::make('Product')
                ->columnSpanFull()
                ->schema([
                    Hidden::make('merchant_id')
                        ->default(function () {
                            $user = Filament::auth()->user();

                            return match (true) {
                                $user instanceof Merchant => $user->id,
                                $user instanceof User => $user->merchant_id,
                                default => null,
                            };
                        })
                        ->required(),

                    TextInput::make('name')
                        ->label('Product Name')
                        ->required()
                        ->unique()
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, $livewire) {
                            $livewire->resetValidation('data.name');
                            $livewire->resetErrorBag('data.name');
                        }),
                    FileUpload::make('product_image')
                        ->label('Product Image')
                        ->image()
                        ->disk('public')
                        ->directory('products/images')
                        ->visibility('public')
                        ->imagePreviewHeight(150)
                        ->maxSize(2048)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->dehydrated(false),

                    Textarea::make('description')
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Section::make('Basics')
                        ->columns(2)
                        ->schema([
                            //                            Select::make('business_id')
                            //                                ->label('Business')
                            //                                ->relationship(
                            //                                    name: 'business',
                            //                                    titleAttribute: 'name',
                            //                                    modifyQueryUsing: function (Builder $query) {
                            //                                        $user = Filament::auth()->user();
                            //
                            //                                        if ($user instanceof Admin) {
                            //                                            return;
                            //                                        }
                            //
                            //                                        // Merchant → only their businesses
                            //                                        $query->where('merchant_id', $user->id);
                            //                                    }
                            //                                )
                            //                                ->preload()
                            //                                ->searchable()
                            //                                ->required(),

                            Select::make('type')
                                ->required()
                                ->options([
                                    'stock' => 'Stock',
                                    'service' => 'Service',
                                    'measured_stock' => 'Measured',
                                    //                                    'custom'         => 'Custom',
                                ])
                                ->reactive()
                                ->afterStateUpdated(fn ($state, callable $set) => $state === 'service' ? $set('track_inventory', false) : null
                                ),

                            Select::make('unit')
                                ->required()
                                ->default('pcs')
                                ->options(ProductUnit::options()),

                            TextInput::make('purchase_price')
                                ->label('Purchase Price')
                                ->numeric()
                                ->prefix('PKR')
                                ->nullable(),

                            TextInput::make('selling_price')
                                ->label('Selling Price')
                                ->numeric()
                                ->prefix('PKR')
                                ->nullable(),
                        ]),

                    //            Section::make('Custom Fields')
                    //                ->columnSpanFull()
                    //                ->description('Define dynamic attributes like Size, Color, Voltage, Karat.')
                    //                ->schema([
                    //                    Repeater::make('options')
                    //                        ->columnSpanFull()
                    //                        ->collapsible()
                    //                        ->columns(2)
                    //                        ->itemLabel(fn ($state) => $state['display_name'] ?? $state['name'] ?? 'Custom Field')
                    //                        ->schema([
                    //
                    //                            Select::make('field_type')
                    //                                ->label('Type')
                    //                                ->required()
                    //                                ->options([
                    //                                    'select'     => 'Select',
                    //                                    'numeric'    => 'Numeric',
                    //                                    'alphabetic' => 'Text',
                    //                                ])
                    //                                ->reactive(),
                    //
                    // //                            Toggle::make('is_required')
                    // //                                ->label('Required')
                    // //                                ->default(false),
                    //
                    //                            TextInput::make('name')
                    //                                ->label('Label')
                    //                                ->maxLength(255)
                    //                                ->required(),
                    // //
                    // //                            TextInput::make('display_name')
                    // //                                ->label('Label')
                    // //                                ->required(),
                    //
                    //                            // ✅ Only for "select" type
                    //                            Repeater::make('values')
                    //                                ->label('Options')
                    //                                ->columnSpanFull()
                    //                                ->visible(fn ($get) => $get('field_type') === 'select')
                    //                                ->columns(2)
                    //                                ->grid(2)
                    //                                ->schema([
                    //                                    TextInput::make('value')
                    //                                        ->required()
                    //                                        ->placeholder('e.g. Small')
                    //                                        ->hiddenLabel(),
                    //                                ])
                    //                                ->minItems(1)
                    //                                ->addActionLabel('+'),
                    //
                    //                            TextInput::make('min')
                    //                                ->label('Min')
                    //                                ->numeric()
                    //                                ->visible(fn ($get) => $get('field_type') === 'numeric'),
                    //
                    //                            TextInput::make('max')
                    //                                ->label('Max')
                    //                                ->numeric()
                    //                                ->visible(fn ($get) => $get('field_type') === 'numeric'),
                    //                        ])
                    //                        ->addActionLabel('+ Add Field'),
                    //                ]),

                    //            Toggle::make('is_stocked')->default(true),
                    Toggle::make('is_active')->default(true),

                ]),
        ]);

    }
}
