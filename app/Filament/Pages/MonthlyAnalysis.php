<?php

namespace App\Filament\Pages;

use App\Enums\FinanceDocumentStatus;
use App\Models\JournalVoucherLine;
use App\Models\LedgerAccount;
use App\Services\Finance\FinanceLedger;
use App\Services\Finance\FinancialStatements;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

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
                    ->description('Totals come from posted journal vouchers (same basis as Trial Balance / P&L).')
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

        $statements = app(FinancialStatements::class);
        $inventoryPurchases = $this->inventoryPurchaseByMonth($merchantId, $year);

        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $period = $statements->forPeriod($merchantId, $year, $m);
            $sales = (float) $period['profit_and_loss']['income_total'];
            $expenses = (float) $period['profit_and_loss']['expense_total'];
            $purchases = (float) ($inventoryPurchases[$m] ?? 0);
            $rows[] = [
                'month' => date('F', mktime(0, 0, 0, $m, 1)),
                'sales' => $sales,
                'purchases' => $purchases,
                'expenses' => $expenses,
                'net' => (float) $period['profit_and_loss']['profit'],
            ];
        }

        return $rows;
    }

    /**
     * Posted inventory debits (account 1400) by month — GL purchase basis.
     *
     * @return array<int, float>
     */
    private function inventoryPurchaseByMonth(string $merchantId, int $year): array
    {
        $accountIds = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('code', FinanceLedger::INVENTORY_ACCOUNT_CODE)
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            return [];
        }

        $lines = JournalVoucherLine::query()
            ->whereIn('ledger_account_id', $accountIds)
            ->where('debit', '>', 0)
            ->whereHas('journalVoucher', function ($q) use ($merchantId, $year): void {
                $q->where('merchant_id', $merchantId)
                    ->where('status', FinanceDocumentStatus::Posted->value)
                    ->whereYear('voucher_date', $year);
            })
            ->with('journalVoucher:id,voucher_date')
            ->get();

        $byMonth = [];
        foreach ($lines as $line) {
            $month = (int) Carbon::parse($line->journalVoucher?->voucher_date)->month;
            $byMonth[$month] = round(($byMonth[$month] ?? 0) + (float) $line->debit, 2);
        }

        return $byMonth;
    }
}
