<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Sales\SaleResource;
use App\Models\Sale;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

class ReceivableAging extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Clock;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 40;

    protected static ?string $title = 'Receivable Aging';

    protected static ?string $navigationLabel = 'Receivable Aging';

    protected string $view = 'filament.pages.receivable-aging';

    public static function canAccess(): bool
    {
        return FinanceAccess::can('receivable_aging');
    }

    /**
     * @return array{buckets: array<string, float>, rows: list<array<string, mixed>>, overdue_total: float}
     */
    public function getAgingData(): array
    {
        $merchantId = FinanceAccess::merchantId();
        $buckets = [
            'current' => 0.0,
            '1_30' => 0.0,
            '31_60' => 0.0,
            '61_90' => 0.0,
            '90_plus' => 0.0,
        ];
        $rows = [];
        $overdueTotal = 0.0;

        if (! $merchantId) {
            return [
                'buckets' => $buckets,
                'rows' => $rows,
                'overdue_total' => 0.0,
            ];
        }

        $sales = Sale::query()
            ->posted()
            ->withoutTrashed()
            ->where('merchant_id', $merchantId)
            ->where('due_amount', '>', 0)
            ->with('customer:id,name')
            ->orderBy('due_date')
            ->get();

        $today = Carbon::today();

        foreach ($sales as $sale) {
            $dueDate = $sale->due_date ? Carbon::parse($sale->due_date)->startOfDay() : Carbon::parse($sale->sale_date)->startOfDay();
            $days = $dueDate->diffInDays($today, false);
            $amount = (float) $sale->due_amount;

            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };

            $buckets[$bucket] += $amount;

            if ($days > 0) {
                $overdueTotal += $amount;
            }

            $rows[] = [
                'sale_no' => $sale->sale_no,
                'customer' => $sale->customer?->name ?? '—',
                'due_date' => $dueDate->format('d/m/Y'),
                'days_overdue' => max(0, (int) $days),
                'due_amount' => $amount,
                'bucket' => $bucket,
                'url' => SaleResource::getUrl('view', ['record' => $sale]),
            ];
        }

        return [
            'buckets' => $buckets,
            'rows' => $rows,
            'overdue_total' => $overdueTotal,
        ];
    }
}
