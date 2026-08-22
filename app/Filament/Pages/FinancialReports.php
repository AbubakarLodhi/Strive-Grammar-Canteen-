<?php

namespace App\Filament\Pages;

use App\Filament\Exports\FinancialReportsExport;
use App\Services\Finance\FinancialStatements;
use App\Support\FinanceAccess;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class FinancialReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 7;

    protected static ?string $title = 'Financial Reports';

    protected static ?string $navigationLabel = 'Financial Reports';

    protected string $view = 'filament.pages.financial-reports';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return FinanceAccess::can('finance_ledger');
    }

    public function mount(): void
    {
        $this->form->fill([
            'year' => (int) now()->year,
            'month' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Period')
                    ->description('Leave month empty to show the full year. Choose a month to show that month only.')
                    ->columns(2)
                    ->schema([
                        Select::make('year')
                            ->label('Year')
                            ->options($this->yearOptions())
                            ->required()
                            ->live()
                            ->native(false),
                        Select::make('month')
                            ->label('Month')
                            ->placeholder('Whole year')
                            ->options($this->monthOptions())
                            ->nullable()
                            ->live()
                            ->native(false),
                    ]),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function statements(): array
    {
        $merchantId = FinanceAccess::merchantId();
        $year = (int) ($this->data['year'] ?? now()->year);
        $month = $this->selectedMonth();

        if (! $merchantId) {
            $window = app(FinancialStatements::class)->periodWindow($year, $month);

            return [
                'period_label' => $window['period_label'],
                'as_at' => $window['end']->format('d/m/Y'),
                'scope' => $window['scope'],
                'amount_label' => $window['amount_label'],
                'trial_balance' => ['rows' => [], 'debit_total' => 0.0, 'credit_total' => 0.0],
                'profit_and_loss' => [
                    'income' => [],
                    'expenses' => [],
                    'income_total' => 0.0,
                    'expense_total' => 0.0,
                    'profit' => 0.0,
                ],
                'balance_sheet' => [
                    'assets' => [],
                    'liabilities' => [],
                    'equity' => [],
                    'asset_total' => 0.0,
                    'liability_total' => 0.0,
                    'equity_total' => 0.0,
                    'period_profit' => 0.0,
                    'profit_label' => $window['scope'] === 'year' ? 'Profit for the year' : 'Profit for the year to date',
                    'financing_total' => 0.0,
                ],
            ];
        }

        return app(FinancialStatements::class)->forPeriod($merchantId, $year, $month);
    }

    /**
     * @return array<int, int>
     */
    private function yearOptions(): array
    {
        $current = (int) now()->year;
        $years = [];

        for ($year = $current; $year >= $current - 6; $year--) {
            $years[$year] = $year;
        }

        return $years;
    }

    /**
     * @return array<int, string>
     */
    private function monthOptions(): array
    {
        $options = [];

        for ($month = 1; $month <= 12; $month++) {
            $options[$month] = Carbon::create(2000, $month, 1)->format('F');
        }

        return $options;
    }

    private function selectedMonth(): ?int
    {
        $month = $this->data['month'] ?? null;

        if ($month === null || $month === '') {
            return null;
        }

        $month = (int) $month;

        return $month >= 1 && $month <= 12 ? $month : null;
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
        $statements = $this->statements();

        return Pdf::loadView('exports.financial-reports-pdf', [
            'company' => config('branding.name'),
            'statements' => $statements,
        ])
            ->setPaper('a4', 'portrait')
            ->download($this->exportFilename('pdf', $statements['period_label'] ?? null));
    }

    private function downloadExcel(): BinaryFileResponse
    {
        $statements = $this->statements();

        return Excel::download(
            new FinancialReportsExport($statements),
            $this->exportFilename('xlsx', $statements['period_label'] ?? null),
        );
    }

    private function exportFilename(string $extension, ?string $periodLabel = null): string
    {
        $period = Str::slug($periodLabel ?: now()->format('F Y'));

        return 'financial-statements-'.$period.'.'.$extension;
    }
}
