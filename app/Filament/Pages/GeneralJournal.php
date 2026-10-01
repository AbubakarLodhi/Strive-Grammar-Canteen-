<?php

namespace App\Filament\Pages;

use App\Enums\FinanceDocumentStatus;
use App\Filament\Exports\GeneralJournalExport;
use App\Models\JournalVoucherLine;
use App\Support\FinanceAccess;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class GeneralJournal extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::QueueList;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 43;

    protected static ?string $title = 'General Journal';

    protected static ?string $navigationLabel = 'General Journal';

    protected string $view = 'filament.pages.general-journal';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return FinanceAccess::can('journal_vouchers') || FinanceAccess::can('finance_ledger');
    }

    public function mount(): void
    {
        $this->form->fill([
            'date_from' => now()->startOfMonth()->subMonths(5)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Filters')
                    ->columns(2)
                    ->schema([
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
        $merchantId = FinanceAccess::merchantId();
        $from = $this->data['date_from'] ?? null;
        $to = $this->data['date_to'] ?? null;

        if (! $merchantId) {
            return collect();
        }

        return JournalVoucherLine::query()
            ->whereHas('journalVoucher', function ($q) use ($merchantId, $from, $to): void {
                $q->where('merchant_id', $merchantId)
                    ->where('status', FinanceDocumentStatus::Posted->value)
                    ->when($from, fn ($qq) => $qq->whereDate('voucher_date', '>=', $from))
                    ->when($to, fn ($qq) => $qq->whereDate('voucher_date', '<=', $to));
            })
            ->with(['journalVoucher', 'ledgerAccount'])
            ->get()
            ->sortBy(fn (JournalVoucherLine $line) => ($line->journalVoucher?->voucher_date?->format('Y-m-d') ?? '').'-'.($line->journalVoucher?->voucher_no ?? '').'-'.$line->sort_order)
            ->values()
            ->map(fn (JournalVoucherLine $line) => (object) [
                'date' => $line->journalVoucher?->voucher_date,
                'voucher_no' => $line->journalVoucher?->voucher_no,
                'account' => ($line->ledgerAccount?->code ?? '').' — '.($line->ledgerAccount?->name ?? ''),
                'description' => $line->description,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
            ]);
    }

    public function debitTotal(): float
    {
        return round((float) $this->rows->sum('debit'), 2);
    }

    public function creditTotal(): float
    {
        return round((float) $this->rows->sum('credit'), 2);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon('heroicon-s-arrow-down-tray')
                ->color('danger')
                ->action(fn () => $this->downloadPdf()),
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon('heroicon-s-table-cells')
                ->color('success')
                ->action(fn () => $this->downloadExcel()),
        ];
    }

    private function downloadPdf(): Response
    {
        $rows = $this->rows;
        $from = $this->data['date_from'] ?? null;
        $to = $this->data['date_to'] ?? null;

        return Pdf::loadView('exports.general-journal-pdf', [
            'company' => config('branding.name'),
            'rows' => $rows,
            'debit_total' => $this->debitTotal(),
            'credit_total' => $this->creditTotal(),
            'period' => trim(($from ?: '…').' → '.($to ?: '…')),
        ])
            ->setPaper('a4', 'landscape')
            ->download($this->exportFilename('pdf'));
    }

    private function downloadExcel(): BinaryFileResponse
    {
        return Excel::download(
            new GeneralJournalExport($this->rows, $this->debitTotal(), $this->creditTotal()),
            $this->exportFilename('xlsx'),
        );
    }

    private function exportFilename(string $extension): string
    {
        $period = Str::slug(($this->data['date_from'] ?? 'from').'-'.($this->data['date_to'] ?? 'to'));

        return 'general-journal-'.$period.'.'.$extension;
    }
}
