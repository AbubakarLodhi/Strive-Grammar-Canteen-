<?php

namespace App\Filament\Resources\SaleReturns\Schemas;

use App\Filament\Resources\Sales\Tables\SalesTable;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleReturnService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class SaleReturnForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Product')
                    ->placeholder('Search by product name or SKU')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::productOptions($search))
                    ->getOptionLabelUsing(fn ($value): ?string => self::productLabel(Product::query()->withoutTrashed()->find($value)))
                    ->live()
                    ->required()
                    ->afterStateUpdated(function (callable $set) {
                        $set('sale_id', null);
                        $set('items', []);
                        $set('subtotal', 0);
                        $set('total_discount', 0);
                        $set('total_tax', 0);
                        $set('total_amount', 0);
                    }),

                Select::make('sale_id')
                    ->label('Customer / Sale')
                    ->placeholder('Select which purchase this product is being returned from')
                    ->helperText('Only sales that still have this product returnable are listed.')
                    ->options(fn (callable $get): array => self::saleOptionsForProduct($get('product_id')))
                    ->searchable()
                    ->visible(fn (callable $get): bool => filled($get('product_id')))
                    ->live()
                    ->required()
                    ->afterStateUpdated(function (callable $set, callable $get, $state) {
                        $sale = $state ? Sale::query()->withoutTrashed()->find($state) : null;

                        if (! $sale) {
                            $set('items', []);
                            $set('subtotal', 0);
                            $set('total_discount', 0);
                            $set('total_tax', 0);
                            $set('total_amount', 0);

                            return;
                        }

                        $productId = $get('product_id');

                        // Pre-fill every line from the sale (so the repeater can still be
                        // used to return other items from the same purchase too), but only
                        // default the quantity for the product the user searched for — the
                        // rest start at 0 so nothing extra gets returned by accident.
                        $items = collect(SaleReturnService::defaultReturnItemsFor($sale))
                            ->map(function (array $item) use ($productId) {
                                if ($item['product_id'] !== $productId) {
                                    $item['quantity'] = 0;
                                }

                                return $item;
                            })
                            ->all();

                        $set('items', $items);
                        SalesTable::recalcReturnTotals($set, $get);
                    }),

                DatePicker::make('return_date')
                    ->default(now())
                    ->required(),

                Textarea::make('reason')
                    ->columnSpanFull(),

                Repeater::make('items')
                    ->label('Items to return')
                    ->default([])
                    ->visible(fn (callable $get): bool => filled($get('sale_id')))
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->afterStateUpdated(fn (callable $set, callable $get) => SalesTable::recalcReturnTotals($set, $get))
                    ->schema([
                        Hidden::make('sale_item_id'),

                        Hidden::make('product_id'),

                        Hidden::make('max_quantity'),

                        Hidden::make('discount'),

                        Hidden::make('tax'),

                        Placeholder::make('product_name')
                            ->label('Product'),

                        Placeholder::make('variant_name')
                            ->label('Variant'),

                        TextInput::make('quantity')
                            ->numeric()
                            ->live()
                            ->minValue(0)
                            ->maxValue(fn ($get) => $get('max_quantity'))
                            ->required()
                            ->disabled(fn ($get) => (int) ($get('max_quantity') ?? 0) <= 0)
                            ->dehydrated()
                            ->afterStateUpdated(fn (callable $set, callable $get) => SalesTable::recalcReturnTotals($set, $get))
                            ->rules([
                                fn ($get) => function ($attribute, $value, $fail) use ($get) {
                                    $max = $get('max_quantity');

                                    if ($value > $max) {
                                        $fail("Return quantity cannot be greater than sold quantity ({$max}).");
                                    }
                                },
                            ]),

                        TextInput::make('unit_price')
                            ->disabled(),
                    ]),

                Section::make('Summary')
                    ->columns(4)
                    ->columnSpanFull()
                    ->visible(fn (callable $get): bool => filled($get('sale_id')))
                    ->schema([
                        Placeholder::make('subtotal_display')
                            ->label('Subtotal')
                            ->live()
                            ->content(fn (callable $get) => 'PKR '.number_format((float) ($get('subtotal') ?? 0), 2)
                            ),

                        Placeholder::make('total_discount_display')
                            ->label('Discount')
                            ->live()
                            ->content(fn (callable $get) => 'PKR '.number_format((float) ($get('total_discount') ?? 0), 2)
                            ),

                        Placeholder::make('total_tax_display')
                            ->label('Tax')
                            ->live()
                            ->content(fn (callable $get) => 'PKR '.number_format((float) ($get('total_tax') ?? 0), 2)
                            ),

                        Placeholder::make('total_amount_display')
                            ->label('Total Amount')
                            ->live()
                            ->content(fn (callable $get) => 'PKR '.number_format((float) ($get('total_amount') ?? 0), 2)
                            ),

                        Hidden::make('subtotal')->default(0)->dehydrated(),
                        Hidden::make('total_discount')->default(0)->dehydrated(),
                        Hidden::make('total_tax')->default(0)->dehydrated(),
                        Hidden::make('total_amount')->default(0)->dehydrated(),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function productOptions(string $search): array
    {
        $merchantId = self::merchantId();

        if (! $merchantId || trim($search) === '') {
            return [];
        }

        return Product::query()
            ->withoutTrashed()
            ->where('merchant_id', $merchantId)
            ->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Product $product): array => [$product->id => self::productLabel($product)])
            ->all();
    }

    private static function productLabel(?Product $product): ?string
    {
        if (! $product) {
            return null;
        }

        return trim($product->name.($product->sku ? " ({$product->sku})" : ''));
    }

    /**
     * Sales that still have the given product returnable, most recent first.
     *
     * @return array<string, string>
     */
    private static function saleOptionsForProduct(?string $productId): array
    {
        $merchantId = self::merchantId();

        if (! $merchantId || ! filled($productId)) {
            return [];
        }

        return Sale::query()
            ->withoutTrashed()
            ->posted()
            ->where('merchant_id', $merchantId)
            ->whereHas('items', fn ($q) => $q->where('product_id', $productId)->where('quantity', '>', 0))
            ->with('customer:id,name')
            ->withSum(
                ['items as returnable_qty' => fn ($q) => $q->where('product_id', $productId)],
                'quantity'
            )
            ->orderByDesc('sale_date')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Sale $sale): array => [$sale->id => self::saleWithQuantityLabel($sale)])
            ->all();
    }

    private static function saleWithQuantityLabel(Sale $sale): string
    {
        $date = $sale->sale_date ? Carbon::parse($sale->sale_date)->format('d/m/Y') : null;
        $qty = (int) ($sale->returnable_qty ?? 0);

        return trim(
            ($sale->customer?->name ? $sale->customer->name.' — ' : '')
            .($sale->sale_no ?: substr($sale->id, 0, 8))
            .($date ? " ({$date})" : '')
            ." · Qty available: {$qty}"
        );
    }

    private static function merchantId(): ?string
    {
        $user = Filament::auth()->user();

        return match (true) {
            $user instanceof Merchant => $user->id,
            $user instanceof User => $user->merchant_id,
            default => null,
        };
    }
}
