<?php

namespace App\Filament\Pages;

use App\Enums\FinanceDocumentStatus;
use App\Enums\LedgerAccountType;
use App\Models\LedgerAccount;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GeneralLedger extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'General Ledger';

    protected static ?string $navigationLabel = 'General Ledger';

    protected string $view = 'filament.pages.general-ledger';

    public static function canAccess(): bool
    {
        return FinanceAccess::can('finance_ledger');
    }

    public function table(Table $table): Table
    {
        $merchantId = FinanceAccess::merchantId();

        return $table
            ->query(
                LedgerAccount::query()
                    ->when(
                        $merchantId,
                        fn ($query) => $query->where('merchant_id', $merchantId),
                        fn ($query) => $query->whereRaw('1 = 0')
                    )
                    ->withSum([
                        'journalLines as posted_debits' => fn ($query) => $query->whereHas(
                            'journalVoucher',
                            fn ($voucher) => $voucher->where('status', FinanceDocumentStatus::Posted->value)
                        ),
                    ], 'debit')
                    ->withSum([
                        'journalLines as posted_credits' => fn ($query) => $query->whereHas(
                            'journalVoucher',
                            fn ($voucher) => $voucher->where('status', FinanceDocumentStatus::Posted->value)
                        ),
                    ], 'credit')
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (LedgerAccountType|string $state): string => $state instanceof LedgerAccountType ? $state->label() : $state),
                TextColumn::make('opening_balance')
                    ->label('Opening')
                    ->numeric(2),
                TextColumn::make('posted_debits')
                    ->label('Debit')
                    ->numeric(2)
                    ->placeholder('0.00'),
                TextColumn::make('posted_credits')
                    ->label('Credit')
                    ->numeric(2)
                    ->placeholder('0.00'),
                TextColumn::make('closing_balance')
                    ->label('Balance')
                    ->state(function (LedgerAccount $record): string {
                        $debit = (float) ($record->posted_debits ?? 0);
                        $credit = (float) ($record->posted_credits ?? 0);
                        $opening = (float) $record->opening_balance;

                        $net = match ($record->type) {
                            LedgerAccountType::Asset, LedgerAccountType::Expense => $opening + $debit - $credit,
                            default => $opening + $credit - $debit,
                        };

                        return number_format($net, 2, '.', '');
                    })
                    ->numeric(2),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('type')
                    ->options(LedgerAccountType::options()),
            ])
            ->paginated([25, 50, 100]);
    }
}
