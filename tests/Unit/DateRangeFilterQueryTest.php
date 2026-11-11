<?php

namespace Tests\Unit;

use App\Filament\Support\DateRangeFilter;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DateRangeFilterQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_range_filter_compares_column_directly_without_where_date(): void
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

        $this->assertStringNotContainsString('date(', $sql);
        $this->assertStringContainsString('"sale_date"', $sql);
        $this->assertContains('2026-01-15', $bindings);
        $this->assertContains('2026-01-20', $bindings);
    }
}
