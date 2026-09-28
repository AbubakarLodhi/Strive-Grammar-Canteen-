<?php

namespace App\Filament\Pages;

use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Sale;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class MonthlyAnalysis extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ChartBarSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 42;

    protected static ?string $title = 'Monthly Analysis';

    protected static ?string $navigationLabel = 'Monthly Analysis';

    protected string $view = 'filament.pages.monthly-analysis';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return FinanceAccess::can('reports') || FinanceAccess::can('finance_ledger');
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
     * @return list<array{month: string, sales: float, purchases: float, expenses: float, net: float}>
     */
    public function getRows(): array
    {
        $merchantId = FinanceAccess::merchantId();
        $year = (int) ($this->data['year'] ?? now()->year);

        if (! $merchantId) {
            return [];
        }

        $salesByMonth = Sale::query()
            ->posted()
            ->withoutTrashed()
            ->where('merchant_id', $merchantId)
            ->whereYear('sale_date', $year)
            ->get(['sale_date', 'total_amount'])
            ->groupBy(fn (Sale $sale) => (int) $sale->sale_date?->month)
            ->map(fn ($group) => round((float) $group->sum('total_amount'), 2));

        $purchasesByMonth = Purchase::query()
            ->where('merchant_id', $merchantId)
            ->whereYear('purchase_date', $year)
            ->get(['purchase_date', 'total_amount'])
            ->groupBy(fn (Purchase $purchase) => (int) $purchase->purchase_date?->month)
            ->map(fn ($group) => round((float) $group->sum('total_amount'), 2));

        $expensesByMonth = Expense::query()
            ->where('merchant_id', $merchantId)
            ->whereYear('expense_date', $year)
            ->get(['expense_date', 'total_amount'])
            ->groupBy(fn (Expense $expense) => (int) $expense->expense_date?->month)
            ->map(fn ($group) => round((float) $group->sum('total_amount'), 2));

        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $s = (float) ($salesByMonth[$m] ?? 0);
            $p = (float) ($purchasesByMonth[$m] ?? 0);
            $e = (float) ($expensesByMonth[$m] ?? 0);
            $rows[] = [
                'month' => date('F', mktime(0, 0, 0, $m, 1)),
                'sales' => $s,
                'purchases' => $p,
                'expenses' => $e,
                'net' => round($s - $p - $e, 2),
            ];
        }

        return $rows;
    }
}
