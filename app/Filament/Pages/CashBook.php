<?php

namespace App\Filament\Pages;

use App\Enums\FinanceDocumentStatus;
use App\Models\JournalVoucherLine;
use App\Models\LedgerAccount;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class CashBook extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 37;

    protected static ?string $title = 'Cash Book';

    protected static ?string $navigationLabel = 'Cash Book';

    protected string $view = 'filament.pages.cash-book';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return FinanceAccess::can('cash_book');
    }

    public function mount(): void
    {
        $merchantId = FinanceAccess::merchantId();
        $cashId = $merchantId
            ? LedgerAccount::query()
                ->where('merchant_id', $merchantId)
                ->where('code', FinanceLedger::CASH_ACCOUNT_CODE)
                ->value('id')
            : null;

        $this->form->fill([
            'account_id' => $cashId,
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Filters')
                    ->columns(3)
                    ->schema([
                        Select::make('account_id')
                            ->label('Cash / bank account')
                            ->options(fn () => $this->accountOptions())
                            ->required()
                            ->live()
                            ->native(false),
                        DatePicker::make('date_from')->label('From')->native(false)->live(),
                        DatePicker::make('date_to')->label('To')->native(false)->live(),
                    ]),
            ]);
    }

    /**
     * @return Collection<int, object>
     */
    public function getRowsProperty(): Collection
    {
        $accountId = $this->data['account_id'] ?? null;
        $from = $this->data['date_from'] ?? null;
        $to = $this->data['date_to'] ?? null;

        if (! $accountId) {
            return collect();
        }

        $account = LedgerAccount::query()->find($accountId);
        $opening = (float) ($account?->opening_balance ?? 0);

        $priorNet = (float) JournalVoucherLine::query()
            ->where('ledger_account_id', $accountId)
            ->whereHas('journalVoucher', function ($q) use ($from): void {
                $q->where('status', FinanceDocumentStatus::Posted->value)
                    ->when($from, fn ($qq) => $qq->whereDate('voucher_date', '<', $from));
            })
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as net')
            ->value('net');

        $running = $opening + $priorNet;

        $lines = JournalVoucherLine::query()
            ->where('ledger_account_id', $accountId)
            ->whereHas('journalVoucher', function ($q) use ($from, $to): void {
                $q->where('status', FinanceDocumentStatus::Posted->value)
                    ->when($from, fn ($qq) => $qq->whereDate('voucher_date', '>=', $from))
                    ->when($to, fn ($qq) => $qq->whereDate('voucher_date', '<=', $to));
            })
            ->with('journalVoucher')
            ->get()
            ->sortBy(fn ($line) => $line->journalVoucher?->voucher_date?->format('Y-m-d').$line->created_at);

        return $lines->map(function (JournalVoucherLine $line) use (&$running) {
            $running += (float) $line->debit - (float) $line->credit;

            return (object) [
                'date' => $line->journalVoucher?->voucher_date,
                'voucher_no' => $line->journalVoucher?->voucher_no,
                'description' => $line->description,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'balance' => round($running, 2),
            ];
        })->values();
    }

    /**
     * @return array<string, string>
     */
    private function accountOptions(): array
    {
        $merchantId = FinanceAccess::merchantId();
        if (! $merchantId) {
            return [];
        }

        return LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where(function ($q): void {
                $q->where('code', FinanceLedger::CASH_ACCOUNT_CODE)->orWhere('is_bank', true);
            })
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (LedgerAccount $a) => [$a->id => $a->code.' — '.$a->name])
            ->all();
    }
}
