<?php

namespace App\Filament\Resources\BankDeposits\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BankDepositInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Deposit')
                ->columns(3)
                ->schema([
                    TextEntry::make('deposit_no')->label('Deposit Slip No'),
                    TextEntry::make('deposit_date')->date('d/m/Y'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                    TextEntry::make('bank_account')
                        ->label('Bank account')
                        ->state(fn ($record) => $record->bankAccount?->bankLabel()),
                    TextEntry::make('sourceAccount.name')->label('Source Account'),
                    TextEntry::make('amount')->numeric(2),
                    TextEntry::make('reference_no')->label('Bank slip number'),
                    TextEntry::make('journalVoucher.voucher_no')->label('Journal Voucher'),
                    TextEntry::make('notes')->columnSpanFull(),
                ]),
        ]);
    }
}
