<?php

namespace App\Filament\Resources\Sales\Schemas;

use App\Services\PaymentLedgerService;
use Filament\Infolists\Components\RepeatableEntry;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SaleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sale Information')
                    ->extraAttributes(['class' => 'blue-section'])
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('sale_no')
                            ->label('Sale Number'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                \App\Models\Sale::STATUS_DRAFT => 'Draft',
                                \App\Models\Sale::STATUS_POSTED => 'Posted',
                                default => (string) $state,
                            })
                            ->color(fn (?string $state): string => match ($state) {
                                \App\Models\Sale::STATUS_DRAFT => 'warning',
                                \App\Models\Sale::STATUS_POSTED => 'success',
                                default => 'gray',
                            }),

                        TextEntry::make('sale_date')
                            ->label('Sale Date')
                            ->date('d/m/Y'),

                        TextEntry::make('customer.name')
                            ->label('Customer'),

                        TextEntry::make('merchant.name')
                            ->label('Merchant'),

                        TextEntry::make('businesses')
                            ->label('Business')
                            ->getStateUsing(function ($record) {
                                $names = $record->items()
                                    ->join('businesses', 'businesses.id', '=', 'sale_items.business_id')
                                    ->select('businesses.name')
                                    ->distinct()
                                    ->pluck('name')
                                    ->filter();

                                return $names->isNotEmpty() ? $names->implode(', ') : '-';
                            }),

                        TextEntry::make('branches')
                            ->label('Branch')
                            ->getStateUsing(function ($record) {
                                $names = $record->items()
                                    ->join('branches', 'branches.id', '=', 'sale_items.branch_id')
                                    ->select('branches.name')
                                    ->distinct()
                                    ->pluck('name')
                                    ->filter();

                                return $names->isNotEmpty() ? $names->implode(', ') : '-';
                            }),

                        TextEntry::make('created_by_display')
                            ->label('Created By')
                            ->getStateUsing(fn ($record) => $record->createdBy?->name ?: ($record->merchant?->name ?: '-')),

                        TextEntry::make('created_at')
                            ->label('Created At')
                            ->dateTime('d/m/Y H:i'),
                    ]),

                Section::make('Products')
                    ->extraAttributes(['class' => 'line-items-section'])
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->schema([
                                TextEntry::make('product.name')
                                    ->label('Product'),

                                TextEntry::make('product.sku')
                                    ->label('SKU'),

                                TextEntry::make('quantity')
                                    ->label('Quantity'),

                                TextEntry::make('unit_price')
                                    ->label('Unit Price')
                                    ->money('PKR'),

                                TextEntry::make('discount')
                                    ->label('Discount (%)')
                                    ->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2) . '%'),

                                TextEntry::make('tax')
                                    ->label('Tax (%)')
                                    ->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2) . '%'),

                                TextEntry::make('line_total')
                                    ->label('Line Total')
                                    ->money('PKR'),
                            ])
                            ->columns(7),
                    ]),

                Section::make('Summary')
                    ->extraAttributes(['class' => 'blue-section'])
                    ->columns(6)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('subtotal')
                            ->label('Subtotal')
                            ->money('PKR'),

                        TextEntry::make('discount')
                            ->label('Discount')
                            ->money('PKR')
                            ->getStateUsing(function ($record) {
                                return $record->items->sum(function ($item) {
                                    $lineTotal = (float) ($item->line_total ?? 0);
                                    $discountRate = (float) ($item->discount ?? 0);

                                    return $lineTotal * ($discountRate / 100);
                                });
                            }),

                        TextEntry::make('tax')
                            ->label('Tax')
                            ->money('PKR')
                            ->getStateUsing(function ($record) {
                                return $record->items->sum(function ($item) {
                                    $lineTotal = (float) ($item->line_total ?? 0);
                                    $discountRate = (float) ($item->discount ?? 0);
                                    $taxRate = (float) ($item->tax ?? 0);
                                    $discountAmount = $lineTotal * ($discountRate / 100);
                                    $taxableAmount = $lineTotal - $discountAmount;

                                    return $taxableAmount * ($taxRate / 100);
                                });
                            }),

                        TextEntry::make('total_amount')
                            ->label('Total Amount')
                            ->money('PKR')
                            ->weight('bold'),

                        TextEntry::make('paid_amount')
                            ->label('Amount Paid')
                            ->money('PKR'),

                        TextEntry::make('due_amount')
                            ->label('Amount Due')
                            ->money('PKR')
                            ->color(fn ($state) => (float) $state > 0 ? 'warning' : null),
                    ]),

                Section::make('Payment')
                    ->extraAttributes(['class' => 'blue-section'])
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('payment_type')
                            ->label('Payment Type')
                            ->formatStateUsing(fn ($state) => ucfirst((string) $state)),

                        TextEntry::make('due_date')
                            ->label('Due Date')
                            ->date('d/m/Y')
                            ->placeholder('—')
                            ->visible(fn ($record) => $record->payment_type === 'credit'),

                        TextEntry::make('paid_amount')
                            ->label('Already Paid')
                            ->money('PKR'),

                        RepeatableEntry::make('payment_history')
                            ->label('Payment History')
                            ->getStateUsing(fn ($record) => PaymentLedgerService::displayablePayments($record))
                            ->schema([
                                TextEntry::make('payment_date')
                                    ->label('Date')
                                    ->date('d/m/Y'),
                                TextEntry::make('entry_type')
                                    ->label('Type')
                                    ->formatStateUsing(fn (?string $state) => ucfirst((string) ($state ?? 'payment'))),
                                TextEntry::make('display_amount')
                                    ->label('Amount')
                                    ->money('PKR'),
                            ])
                            ->columns(3),
                    ]),

                Section::make('Notes')
                    ->extraAttributes(['class' => 'blue-section'])
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('notes')
                            ->label('Notes')
                            ->placeholder('No notes available')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
