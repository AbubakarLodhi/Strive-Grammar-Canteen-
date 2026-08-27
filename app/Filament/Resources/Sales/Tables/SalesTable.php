<?php

namespace App\Filament\Resources\Sales\Tables;

use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Sale;
use App\Services\SaleDeletionService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('sale_no')
                    ->label('Sale No.')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('sale_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->sortable()
                    ->limit(30)
                    ->searchable(),

                TextColumn::make('merchant.name')
                    ->label('Merchant')
                    ->sortable()
                    ->limit(30)
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('businesses')
                    ->label('Business')
                    ->badge()
                    ->color('primary')
                    ->getStateUsing(function (Sale $record) {
                        $names = $record->items()
                            ->join('businesses', 'businesses.id', '=', 'sale_items.business_id')
                            ->select('businesses.name')
                            ->distinct()
                            ->pluck('name');

                        $visible = $names->take(2);
                        $hidden  = $names->count() - $visible->count();

                        if ($hidden > 0) {
                            $visible->push('+' . $hidden);
                        }

                        return $visible->toArray();
                    })
                    ->sortable(false)
                    ->toggleable(),

                TextColumn::make('branches')
                    ->label('Branch')
                    ->badge()
                    ->color('success')
                    ->getStateUsing(function (Sale $record) {
                        $names = $record->items()
                            ->join('branches', 'branches.id', '=', 'sale_items.branch_id')
                            ->select('branches.name')
                            ->distinct()
                            ->pluck('name');

                        $visible = $names->take(2);
                        $hidden  = $names->count() - $visible->count();

                        if ($hidden > 0) {
                            $visible->push('+' . $hidden);
                        }

                        return $visible->toArray();
                    })
                    ->sortable(false)
                    ->toggleable(),


                TextColumn::make('products')
                    ->label('Products')
                    ->badge()
                    ->color('gray')
                    ->getStateUsing(function (Sale $record) {
                        $names = $record->items
                            ->map(fn ($item) => $item->product?->name)
                            ->filter()
                            ->unique()
                            ->values();

                        $visible = $names->take(2);
                        $hidden = $names->count() - $visible->count();

                        if ($hidden > 0) {
                            $visible->push('+' . $hidden);
                        }

                        return $visible->isNotEmpty() ? $visible->toArray() : ['—'];
                    })
                    ->toggleable(),

                TextColumn::make('items_count')
                    ->label('Line items')
                    ->counts('items')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('PKR')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('items_discount_total')
                    ->label('Discount')
                    ->money('PKR')
                    ->getStateUsing(function (Sale $record) {
                        return $record->items->sum(function ($item) {
                            $lineTotal = (float) ($item->line_total ?? 0);
                            $discountRate = (float) ($item->discount ?? 0);

                            return $lineTotal * ($discountRate / 100);
                        });
                    })
                    ->sortable(false)
                    ->toggleable(),

                TextColumn::make('items_tax_total')
                    ->label('Tax')
                    ->money('PKR')
                    ->getStateUsing(function (Sale $record) {
                        return $record->items->sum(function ($item) {
                            $lineTotal = (float) ($item->line_total ?? 0);
                            $discountRate = (float) ($item->discount ?? 0);
                            $taxRate = (float) ($item->tax ?? 0);
                            $discountAmount = $lineTotal * ($discountRate / 100);
                            $taxableAmount = $lineTotal - $discountAmount;

                            return $taxableAmount * ($taxRate / 100);
                        });
                    })
                    ->sortable(false)
                    ->toggleable(),

                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('PKR')
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('paid_amount')
                    ->label('Paid')
                    ->money('PKR')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('due_amount')
                    ->label('Due')
                    ->money('PKR')
                    ->sortable()
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : null),

                TextColumn::make('payment_type')
                    ->label('Payment')
                    ->badge()
                    ->color(fn ($state) => $state === 'credit' ? 'warning' : 'success')
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('return_status')
                    ->label('Return')
                    ->badge()
                    ->color(fn (mixed $state): string => match ($state) {
                        'Partially Returned' => 'warning',
                        'Returned' => 'success',
                        default => 'gray',
                    })
                    ->getStateUsing(function (Sale $record) {
                        if (! $record->returns()->exists()) {
                            return '-';
                        }

                        return self::hasReturnableItems($record)
                            ? 'Partially Returned'
                            : 'Returned';
                    })
                    ->toggleable(),

                TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([

                SelectFilter::make('customer_id')
                    ->relationship(
                        'activeCustomer',
                        'name',
                        modifyQueryUsing: function (Builder $query) {
                            CustomerResource::scopeVisibleCustomers(
                                $query->withoutTrashed(),
                                Filament::auth()->user(),
                            );
                        }
                    )
                    ->label('Customer')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('business_id')
                    ->label('Business')
                    ->options(function () {
                        $user = Filament::auth()->user();

                        $merchantId = match (true) {
                            $user instanceof \App\Models\Merchant => $user->id,
                            $user instanceof \App\Models\User     => $user->merchant_id,
                            default                               => null,
                        };

                        if (! $merchantId) {
                            return [];
                        }

                        $query = Business::query()
                            ->withoutTrashed()
                            ->where('merchant_id', $merchantId);

                        if ($user instanceof \App\Models\User) {
                            $query->whereHas('users', fn ($q) =>
                            $q->where('users.id', $user->id)
                            );
                        }

                        return $query
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->query(function (Builder $query, array $data) {
                        if (empty($data['value'])) {
                            return;
                        }

                        $query->whereHas('items', fn ($q) =>
                        $q->where('sale_items.business_id', $data['value'])
                        );
                    }),

                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->searchable()
                    ->options(function ($livewire) {

                        $user = Filament::auth()->user();

                        $merchantId = match (true) {
                            $user instanceof \App\Models\Merchant => $user->id,
                            $user instanceof \App\Models\User     => $user->merchant_id,
                            default                               => null,
                        };

                        if (! $merchantId) {
                            return [];
                        }

                        $businessId = $livewire->getTableFilterState('business_id')['value'] ?? null;

                        $query = Branch::query()
                            ->withoutTrashed()
                            ->where('merchant_id', $merchantId);

                        if ($businessId) {
                            $query->where('business_id', $businessId);
                        }

                        if ($user instanceof \App\Models\User) {
                            $query->whereHas('users', fn ($q) =>
                            $q->where('users.id', $user->id)
                            );
                        }

                        return $query
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->query(function (Builder $query, array $data) {
                        if (empty($data['value'])) {
                            return;
                        }

                        $query->whereHas('items', fn ($q) =>
                        $q->where('sale_items.branch_id', $data['value'])
                        );
                    }),

                SelectFilter::make('payment_type')
                    ->label('Payment Type')
                    ->options([
                        'cash' => 'Cash',
                        'credit' => 'Credit',
                    ]),

                Filter::make('sale_date_range')
                    ->label('Sale Date')
                    ->schema([
                        DatePicker::make('from_date')
                            ->label('From Date'),
                        DatePicker::make('to_date')
                            ->label('To Date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from_date'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('sale_date', '>=', $date),
                            )
                            ->when(
                                $data['to_date'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('sale_date', '<=', $date),
                            );
                    }),

            ])
            ->recordUrl(fn (Sale $record) =>
            auth(Filament::getCurrentPanel()->getAuthGuard())
                ->user()
                ?->hasPermissionTo('sales.view', Filament::getCurrentPanel()->getAuthGuard())
                ? SaleResource::getUrl('view', ['record' => $record])
                : null
            )
            ->recordActions([
                ViewAction::make()
                    ->color('info')
                    ->label('')
                    ->tooltip('View')
                    ->visible(fn () =>
                    auth(Filament::getCurrentPanel()->getAuthGuard())
                        ->user()?->hasPermissionTo('sales.view', Filament::getCurrentPanel()->getAuthGuard())
                    ),

                Action::make('invoice')
                    ->icon('heroicon-s-document-text')
                    ->color('gray')
                    ->label(' ')
                    ->tooltip('Invoice')
                    ->url(fn (Sale $record): string => route('invoices.show', [
                        'type' => 'sale',
                        'id' => $record->id,
                    ]))
                    ->visible(fn () =>
                        auth(Filament::getCurrentPanel()->getAuthGuard())
                            ->user()?->hasPermissionTo('sales.view', Filament::getCurrentPanel()->getAuthGuard())
                    ),

                Action::make('return_sale')
                    ->icon('heroicon-s-arrow-uturn-left')
                    ->color('danger')
                    ->label(' ')
                    ->tooltip('Return Sale')
                    ->modalHeading('Return Sale')
                    ->modalWidth('7xl')
                    ->form(fn (Sale $record) => self::returnForm($record))
                    ->action(function (Sale $record, array $data) {
                        \App\Services\SaleReturnService::createReturn($record, $data);
                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Sale returned')
                            ->send();
                    })
                    ->visible(fn (Sale $record) =>
                        self::hasReturnableItems($record)
                        && (
                            auth(Filament::getCurrentPanel()->getAuthGuard())
                                ->user()?->hasPermissionTo('sales.delete', Filament::getCurrentPanel()->getAuthGuard())
                            || auth(Filament::getCurrentPanel()->getAuthGuard())
                                ->user()?->hasPermissionTo('sales.update', Filament::getCurrentPanel()->getAuthGuard())
                        )
                    ),

                EditAction::make()
                    ->color('warning')
                    ->label('')
                    ->tooltip('Edit')
                    ->visible(fn (Sale $record) =>
                    auth(Filament::getCurrentPanel()->getAuthGuard())
                        ->user()?->hasPermissionTo('sales.update', Filament::getCurrentPanel()->getAuthGuard())
                        && (! $record->returns()->exists() || self::hasReturnableItems($record))
                    ),

                DeleteAction::make()
                    ->color('danger')
                    ->label('')
                    ->tooltip('Delete')
                    ->visible(fn () =>
                    auth(Filament::getCurrentPanel()->getAuthGuard())
                        ->user()?->hasPermissionTo('sales.delete', Filament::getCurrentPanel()->getAuthGuard())
                    )
                    ->before(function (DeleteAction $action, ?Sale $record): void {
                        if (! $record) {
                            return;
                        }

                        app(SaleDeletionService::class)->prepare($record);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () =>
                        auth(Filament::getCurrentPanel()->getAuthGuard())
                            ->user()?->hasPermissionTo('sales.delete', Filament::getCurrentPanel()->getAuthGuard())
                        )
                        ->before(function (DeleteBulkAction $action, $records): void {
                            foreach ($records as $record) {
                                if ($record instanceof Sale) {
                                    app(SaleDeletionService::class)->prepare($record);
                                }
                            }
                        }),
                ]),
            ])
            ->defaultSort('sale_date', 'desc');
    }


    public static function returnForm(Sale $sale): array
    {
        // Make sure relationships are loaded
        $sale->loadMissing('items.product', 'items.variants.variant');

        $summary = self::prefillReturnSummary($sale);

        return [
            DatePicker::make('return_date')
                ->default(now())
                ->required(),

            Textarea::make('reason'),

            Repeater::make('items')
                ->default(
                    $sale->items->map(function ($item) {

                        $variant = $item->variants->first();
                        $variantModel = $variant?->variant;


                        $variantLabel = $variantModel
                            ? (
                                $variantModel->name
                                ?? $variantModel->sku
                                ?? $variantModel->option_values
                                ?? substr($variantModel->id, 0, 8)
                            )
                            : '-';

                        return [
                            'sale_item_id' => $item->id,
                            'product_name' => $item->product?->name ?? 'Product',
                            'variant_name' => $variantLabel,
                            'max_quantity' => max(0, (int) $item->quantity),
                            'quantity' => max(0, (int) $item->quantity),
                            'unit_price' => $item->unit_price,
                            'discount' => $item->discount ?? 0,
                            'tax' => $item->tax ?? 0,
                        ];
                    })->toArray()
                )
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->afterStateHydrated(fn (callable $set, callable $get) => self::recalcReturnTotals($set, $get))
                ->afterStateUpdated(fn (callable $set, callable $get) => self::recalcReturnTotals($set, $get))
                ->schema([

                    Hidden::make('sale_item_id'),

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
                        ->afterStateUpdated(fn (callable $set, callable $get) => self::recalcReturnTotals($set, $get))
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
                ->schema([
                    Placeholder::make('subtotal_display')
                        ->label('Subtotal')
                        ->live()
                        ->extraAttributes(['data-summary' => 'subtotal'])
                        ->content(fn (callable $get) =>
                        'PKR ' . number_format((float) ($get('subtotal') ?? 0), 2)
                        ),

                    Placeholder::make('total_discount_display')
                        ->label('Discount')
                        ->live()
                        ->extraAttributes(['data-summary' => 'discount'])
                        ->content(fn (callable $get) =>
                        'PKR ' . number_format((float) ($get('total_discount') ?? 0), 2)
                        ),

                    Placeholder::make('total_tax_display')
                        ->label('Tax')
                        ->live()
                        ->extraAttributes(['data-summary' => 'tax'])
                        ->content(fn (callable $get) =>
                        'PKR ' . number_format((float) ($get('total_tax') ?? 0), 2)
                        ),

                    Placeholder::make('total_amount_display')
                        ->label('Total Amount')
                        ->live()
                        ->extraAttributes(['data-summary' => 'total'])
                        ->content(fn (callable $get) =>
                        'PKR ' . number_format((float) ($get('total_amount') ?? 0), 2)
                        ),

                    Hidden::make('subtotal')->default($summary['subtotal'])->dehydrated(),
                    Hidden::make('total_discount')->default($summary['total_discount'])->dehydrated(),
                    Hidden::make('total_tax')->default($summary['total_tax'])->dehydrated(),
                    Hidden::make('total_amount')->default($summary['total_amount'])->dehydrated(),
                ]),
        ];
    }

    private static function recalcReturnTotals(callable $set, callable $get): void
    {
        $items = $get('items') ?? [];

        $subtotal = 0.0;
        $totalDiscount = 0.0;
        $totalTax = 0.0;

        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 0);
            $unit = (float) ($item['unit_price'] ?? 0);
            $lineSubtotal = $qty * $unit;

            $discountRate = (float) ($item['discount'] ?? 0);
            $taxRate = (float) ($item['tax'] ?? 0);

            $discountAmount = $lineSubtotal * ($discountRate / 100);
            $taxableAmount = max(0, $lineSubtotal - $discountAmount);
            $taxAmount = $taxableAmount * ($taxRate / 100);

            $subtotal += $lineSubtotal;
            $totalDiscount += $discountAmount;
            $totalTax += $taxAmount;
        }

        $set('subtotal', round($subtotal, 2));
        $set('total_discount', round($totalDiscount, 2));
        $set('total_tax', round($totalTax, 2));
        $set('total_amount', round($subtotal - $totalDiscount + $totalTax, 2));
    }

    private static function prefillReturnSummary(Sale $sale): array
    {
        $subtotal = 0.0;
        $totalDiscount = 0.0;
        $totalTax = 0.0;

        foreach ($sale->items as $item) {
            $qty = (float) ($item->quantity ?? 0);
            $unit = (float) ($item->unit_price ?? 0);
            $lineSubtotal = $qty * $unit;

            $discountRate = (float) ($item->discount ?? 0);
            $taxRate = (float) ($item->tax ?? 0);

            $discountAmount = $lineSubtotal * ($discountRate / 100);
            $taxableAmount = max(0, $lineSubtotal - $discountAmount);
            $taxAmount = $taxableAmount * ($taxRate / 100);

            $subtotal += $lineSubtotal;
            $totalDiscount += $discountAmount;
            $totalTax += $taxAmount;
        }

        return [
            'subtotal' => round($subtotal, 2),
            'total_discount' => round($totalDiscount, 2),
            'total_tax' => round($totalTax, 2),
            'total_amount' => round($subtotal - $totalDiscount + $totalTax, 2),
        ];
    }

    private static function hasReturnableItems(Sale $sale): bool
    {
        foreach ($sale->items as $item) {
            $itemQty = (int) ($item->quantity ?? 0);
            $variantQty = (int) $item->variants->sum('quantity');
            if (max($itemQty, $variantQty) > 0) {
                return true;
            }
        }

        return false;
    }

}
