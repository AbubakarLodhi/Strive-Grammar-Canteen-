<?php

namespace App\Filament\Resources\CashVouchers\Schemas;

use App\Models\CashVoucher;
use App\Models\LedgerAccount;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CashVoucherInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cash voucher')
                ->columns(3)
                ->schema([
                    TextEntry::make('voucher_no')->label('Voucher No'),
                    TextEntry::make('voucher_date')->date('d/m/Y'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                    TextEntry::make('direction')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                    TextEntry::make('cash_account')
                        ->label('Cash / bank account')
                        ->state(fn (CashVoucher $record): string => self::ledgerLabel($record->cashAccount)),
                    TextEntry::make('counter_account')
                        ->label('Counter account')
                        ->state(fn (CashVoucher $record): string => self::ledgerLabel($record->counterAccount)),
                    TextEntry::make('amount')->numeric(2),
                    TextEntry::make('reference_no')->label('Reference No'),
                    TextEntry::make('journalVoucher.voucher_no')->label('Journal Voucher'),
                    TextEntry::make('notes')->columnSpanFull(),
                ]),
        ]);
    }

    private static function ledgerLabel(?LedgerAccount $account): string
    {
        if (! $account) {
            return '—';
        }

        if ($account->is_bank) {
            return $account->bankLabel();
        }

        return $account->code.' — '.$account->name;
    }
}
