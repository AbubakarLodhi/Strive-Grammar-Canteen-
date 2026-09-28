<?php

namespace App\Filament\Resources\BankCheques\Schemas;

use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BankChequeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cheque')
                ->columns(3)
                ->schema([
                    TextEntry::make('direction')
                        ->formatStateUsing(fn (ChequeDirection|string $state): string => $state instanceof ChequeDirection ? $state->label() : (string) $state)
                        ->badge(),
                    TextEntry::make('status')
                        ->formatStateUsing(fn (ChequeStatus|string $state): string => $state instanceof ChequeStatus ? $state->label() : (string) $state)
                        ->badge()
                        ->color(fn (ChequeStatus|string $state): string => match ($state instanceof ChequeStatus ? $state : ChequeStatus::tryFrom((string) $state)) {
                            ChequeStatus::Pending => 'warning',
                            ChequeStatus::Cleared => 'success',
                            ChequeStatus::Bounced => 'danger',
                            ChequeStatus::Cancelled => 'gray',
                            default => 'gray',
                        }),
                    TextEntry::make('cheque_number')->label('Cheque No.'),
                    TextEntry::make('cheque_date')->date('d/m/Y'),
                    TextEntry::make('amount')->money('PKR'),
                    TextEntry::make('bankAccount.name')
                        ->label('Bank')
                        ->formatStateUsing(fn ($state, $record): string => $record->bankAccount?->bankLabel() ?? (string) $state),
                    TextEntry::make('chequeBook.book_no')->label('Cheque book')->placeholder('—'),
                    TextEntry::make('payee_name')->placeholder('—'),
                    TextEntry::make('payer_name')->placeholder('—'),
                    TextEntry::make('counterAccount.name')->label('Counter account'),
                    TextEntry::make('cleared_at')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('bounced_at')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('notes')->columnSpanFull()->placeholder('—'),
                ]),
        ]);
    }
}
