<?php

namespace App\Filament\Pages;

use App\Models\Expense;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ExpenseSummary extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ReceiptPercent;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 41;

    protected static ?string $title = 'Expense Summary';

    protected static ?string $navigationLabel = 'Expense Summary';

    protected string $view = 'filament.pages.expense-summary';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return FinanceAccess::can('expense_reports');
    }

    public function mount(): void
    {
        $this->form->fill([
            'year' => (int) now()->year,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Period')
                    ->schema([
                        Select::make('year')
                            ->label('Year')
                            ->options(collect(range((int) now()->year, (int) now()->year - 5))
                                ->mapWithKeys(fn (int $y) => [$y => (string) $y])
                                ->all())
                            ->required()
                            ->live()
                            ->native(false),
                    ]),
            ]);
    }

    /**
     * @return array{by_branch: list<array{label: string, total: float}>, by_month: list<array{label: string, total: float}>, grand_total: float}
     */
    public function getSummary(): array
    {
        $merchantId = FinanceAccess::merchantId();
        $year = (int) ($this->data['year'] ?? now()->year);

        if (! $merchantId) {
            return ['by_branch' => [], 'by_month' => [], 'grand_total' => 0.0];
        }

        $expenses = Expense::query()
            ->where('merchant_id', $merchantId)
            ->whereYear('expense_date', $year)
            ->with('branch:id,name')
            ->get(['id', 'branch_id', 'expense_date', 'total_amount']);

        $byBranch = $expenses
            ->groupBy(fn (Expense $expense) => $expense->branch?->name ?: 'Unassigned')
            ->map(fn ($group, $label) => [
                'label' => (string) $label,
                'total' => round((float) $group->sum('total_amount'), 2),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        $byMonth = [];
        for ($m = 1; $m <= 12; $m++) {
            $total = $expenses
                ->filter(fn (Expense $expense) => (int) $expense->expense_date?->month === $m)
                ->sum('total_amount');
            $byMonth[] = [
                'label' => date('M', mktime(0, 0, 0, $m, 1)),
                'total' => round((float) $total, 2),
            ];
        }

        return [
            'by_branch' => $byBranch,
            'by_month' => $byMonth,
            'grand_total' => round((float) $expenses->sum('total_amount'), 2),
        ];
    }
}
