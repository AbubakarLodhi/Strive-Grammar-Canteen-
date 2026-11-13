<?php

namespace Tests\Unit;

use App\Filament\Support\DateRangeFilter;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DateRangeFilterQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_range_filter_normalizes_bounds_and_uses_where_date(): void
    {
        $filter = DateRangeFilter::make('sale_date_range', 'sale_date', 'Sale Date');

        $query = Sale::query();
        $filter->apply($query, [
            'isActive' => true,
            'from_date' => '2026-01-15 14:22:09',
            'to_date' => '2026-01-20 08:01:44',
        ]);

        $sql = strtolower($query->toSql());
        $bindings = $query->getBindings();

        $this->assertTrue(
            str_contains($sql, 'date("sale_date")') || str_contains($sql, 'strftime('),
            'Expected a date-safe comparison on sale_date.',
        );
        $this->assertContains('2026-01-15', $bindings);
        $this->assertContains('2026-01-20', $bindings);
    }
}
