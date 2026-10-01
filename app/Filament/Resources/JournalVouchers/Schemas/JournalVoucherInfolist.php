<?php

namespace App\Filament\Resources\JournalVouchers\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JournalVoucherInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Voucher')
                ->columns(3)
                ->schema([
                    TextEntry::make('voucher_no')->label('Voucher No'),
                    TextEntry::make('voucher_date')->date('d/m/Y'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                    TextEntry::make('source_type')
                        ->label('Source')
                        ->badge()
                        ->formatStateUsing(function (?string $state): string {
                            if (! filled($state)) {
                                return 'Manual';
                            }

                            return match (class_basename($state)) {
                                'Purchase' => 'Purchase',
                                'PurchaseReturn' => 'Purchase return',
                                'Expense' => 'Expense',
                                'Payroll' => 'Payroll',
                                'BankDeposit' => 'Bank deposit',
                                'CashVoucher' => 'Cash voucher',
                                default => class_basename($state),
                            };
                        }),
                    TextEntry::make('vendor.name')
                        ->label('Vendor')
                        ->placeholder('—'),
                    TextEntry::make('narration')->columnSpanFull(),
                ]),
            Section::make('Entries')
                ->schema([
                    RepeatableEntry::make('lines')
                        ->schema([
                            TextEntry::make('ledgerAccount.code')
                                ->label('Code'),
                            TextEntry::make('ledgerAccount.name')->label('Account'),
                            TextEntry::make('description'),
                            TextEntry::make('debit')->numeric(2),
                            TextEntry::make('credit')->numeric(2),
                        ])
                        ->columns(5),
                ]),
        ]);
    }
}
