<?php

namespace App\Filament\Resources\OnlineBankTransfers\Schemas;

use App\Models\LedgerAccount;
use App\Models\OnlineBankTransfer;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OnlineBankTransferInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Online transfer')
                ->columns(3)
                ->schema([
                    TextEntry::make('transfer_no')->label('Transfer No'),
                    TextEntry::make('transfer_date')->date('d/m/Y'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                    TextEntry::make('from_account')
                        ->label('From account')
                        ->state(fn (OnlineBankTransfer $record): string => self::ledgerLabel($record->fromAccount)),
                    TextEntry::make('to_account')
                        ->label('To account')
                        ->state(fn (OnlineBankTransfer $record): string => self::ledgerLabel($record->toAccount)),
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

        return $account->name;
    }
}
