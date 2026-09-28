<?php

namespace App\Filament\Pages;

use App\Enums\FinanceDocumentStatus;
use App\Enums\LedgerAccountType;
use App\Models\JournalVoucherLine;
use App\Models\LedgerAccount;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public static function canAccess(): bool
    {
        return FinanceAccess::can('finance_ledger');
    }

    public function mount(): void
    {
        $this->dateFrom = now()->startOfMonth()->toDateString();
        $this->dateTo = now()->toDateString();
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

    public function updatedDateFrom(): void
    {
        $this->resetTable();
    }

    public function updatedDateTo(): void
    {
        $this->resetTable();
    }

    public function getSelectedAccount(): ?LedgerAccount
    {
        if (! $this->selectedAccountId) {
            return null;
        }

        return LedgerAccount::query()->find($this->selectedAccountId);
    }

    /**
     * @return Collection<int, object>
     */
    public function getLedgerRowsProperty(): Collection
    {
        $account = $this->getSelectedAccount();

        if (! $account) {
            return collect();
        }

        $from = $this->dateFrom;
        $to = $this->dateTo;
        $opening = (float) ($account->opening_balance ?? 0);
        $debitNormal = $this->isDebitNormal($account->type);

        $prior = JournalVoucherLine::query()
            ->where('ledger_account_id', $account->id)
            ->whereHas('journalVoucher', function ($q) use ($from): void {
                $q->where('status', FinanceDocumentStatus::Posted->value)
                    ->when(FinanceAccess::merchantId(), fn ($qq, $mid) => $qq->where('merchant_id', $mid))
                    ->when($from, fn ($qq) => $qq->whereDate('voucher_date', '<', $from));
            })
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')
            ->first();

        $priorDebit = (float) ($prior?->debit_total ?? 0);
        $priorCredit = (float) ($prior?->credit_total ?? 0);
        $running = $debitNormal
            ? $opening + $priorDebit - $priorCredit
            : $opening + $priorCredit - $priorDebit;

        $rows = collect([
            (object) [
                'date' => $from ? Carbon::parse($from) : null,
                'voucher_no' => '—',
                'description' => 'Opening balance',
                'debit' => 0.0,
                'credit' => 0.0,
                'balance' => round($running, 2),
                'is_opening' => true,
            ],
        ]);

        $lines = JournalVoucherLine::query()
            ->where('ledger_account_id', $account->id)
            ->whereHas('journalVoucher', function ($q) use ($from, $to): void {
                $q->where('status', FinanceDocumentStatus::Posted->value)
                    ->when(FinanceAccess::merchantId(), fn ($qq, $mid) => $qq->where('merchant_id', $mid))
                    ->when($from, fn ($qq) => $qq->whereDate('voucher_date', '>=', $from))
                    ->when($to, fn ($qq) => $qq->whereDate('voucher_date', '<=', $to));
            })
            ->with('journalVoucher')
            ->get()
            ->sortBy(fn (JournalVoucherLine $line) => ($line->journalVoucher?->voucher_date?->format('Y-m-d') ?? '').$line->created_at);

        foreach ($lines as $line) {
            $debit = (float) $line->debit;
            $credit = (float) $line->credit;
            $running = $debitNormal
                ? $running + $debit - $credit
                : $running + $credit - $debit;

            $rows->push((object) [
                'date' => $line->journalVoucher?->voucher_date,
                'voucher_no' => $line->journalVoucher?->voucher_no,
                'description' => $line->description,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => round($running, 2),
                'is_opening' => false,
            ]);
        }

        return $rows->values();
    }

    public function table(Table $table): Table
    {
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
                    ->color('primary')
                    ->weight('medium')
                    ->action(
                        Action::make('openLedger')
                            ->label('Open ledger')
                            ->action(fn (LedgerAccount $record) => $this->openAccount((string) $record->id))
                    ),
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
            ->recordActions([
                Action::make('openLedger')
                    ->label('Open')
                    ->icon(Heroicon::Eye)
                    ->action(fn (LedgerAccount $record) => $this->openAccount((string) $record->id)),
            ])
            ->recordAction('openLedger')
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('type')->options(LedgerAccountType::options()),
            ])
            ->paginated([25, 50, 100]);
    }

    private function isDebitNormal(LedgerAccountType|string|null $type): bool
    {
        $type = $type instanceof LedgerAccountType ? $type : LedgerAccountType::tryFrom((string) $type);

        return in_array($type, [LedgerAccountType::Asset, LedgerAccountType::Expense], true);
    }
}
