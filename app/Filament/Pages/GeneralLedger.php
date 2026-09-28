<?php

namespace App\Filament\Pages;

use App\Enums\FinanceDocumentStatus;
use App\Enums\LedgerAccountType;
use App\Models\JournalVoucherLine;
use App\Models\LedgerAccount;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GeneralLedger extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'General Ledger';

    protected static ?string $navigationLabel = 'General Ledger';

    protected string $view = 'filament.pages.general-ledger';

    public ?string $selectedAccountId = null;

    public static function canAccess(): bool
    {
        return FinanceAccess::can('finance_ledger');
    }

    public function openAccount(string $accountId): void
    {
        $this->selectedAccountId = $accountId;
        $this->resetTable();
    }

    public function clearAccount(): void
    {
        $this->selectedAccountId = null;
        $this->resetTable();
    }

    public function getSelectedAccount(): ?LedgerAccount
    {
        if (! $this->selectedAccountId) {
            return null;
        }

        return LedgerAccount::query()->find($this->selectedAccountId);
    }

    public function table(Table $table): Table
    {
        if ($this->selectedAccountId) {
            return $this->transactionsTable($table);
        }

        return $this->accountsTable($table);
    }

    protected function accountsTable(Table $table): Table
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
            )
            ->columns([
                TextColumn::make('code')->sortable()->searchable(),
                TextColumn::make('name')
                    ->label('Account')
                    ->searchable()
                    ->sortable()
                    ->url(fn (LedgerAccount $record): string => '#')
                    ->action(fn (LedgerAccount $record) => $this->openAccount($record->id)),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (LedgerAccountType|string $state): string => $state instanceof LedgerAccountType ? $state->label() : $state),
                TextColumn::make('opening_balance')
                    ->label('Opening')
                    ->numeric(2),
                TextColumn::make('balance')
                    ->label('Balance')
                    ->state(fn (LedgerAccount $record): string => $record->postedBalance())
                    ->numeric(2),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('type')->options(LedgerAccountType::options()),
            ])
            ->paginated([25, 50, 100]);
    }

    protected function transactionsTable(Table $table): Table
    {
        $accountId = $this->selectedAccountId;

        return $table
            ->query(
                JournalVoucherLine::query()
                    ->where('ledger_account_id', $accountId)
                    ->whereHas('journalVoucher', fn (Builder $q) => $q
                        ->where('status', FinanceDocumentStatus::Posted->value)
                        ->when(FinanceAccess::merchantId(), fn ($qq, $mid) => $qq->where('merchant_id', $mid))
                    )
                    ->with('journalVoucher')
            )
            ->columns([
                TextColumn::make('journalVoucher.voucher_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('journalVoucher.voucher_no')->label('Voucher'),
                TextColumn::make('description')->limit(40),
                TextColumn::make('debit')->numeric(2),
                TextColumn::make('credit')->numeric(2),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label('From')->native(false),
                        DatePicker::make('until')->label('To')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereHas(
                                'journalVoucher',
                                fn ($vq) => $vq->whereDate('voucher_date', '>=', $date)
                            ))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereHas(
                                'journalVoucher',
                                fn ($vq) => $vq->whereDate('voucher_date', '<=', $date)
                            ));
                    }),
            ])
            ->paginated([25, 50, 100]);
    }
}
