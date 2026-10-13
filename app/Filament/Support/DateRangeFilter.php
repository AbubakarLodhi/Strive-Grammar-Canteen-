<?php

namespace App\Filament\Support;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DateRangeFilter
{
    public static function make(string $name, string $column, string $label): Filter
    {
        return Filter::make($name)
            ->label($label)
            ->columns(2)
            ->schema([
                DatePicker::make('from_date')
                    ->label('From Date'),
                DatePicker::make('to_date')
                    ->label('To Date'),
            ])
            ->query(function (Builder $query, array $data) use ($column): Builder {
                return $query
                    ->when(
                        filled($data['from_date'] ?? null),
                        fn (Builder $query) => $query->whereDate($column, '>=', self::normalize($data['from_date'])),
                    )
                    ->when(
                        filled($data['to_date'] ?? null),
                        fn (Builder $query) => $query->whereDate($column, '<=', self::normalize($data['to_date'])),
                    );
            });
    }

    /**
     * Filament's date picker commits the clicked day with the current time
     * of day attached (e.g. "2026-10-09 00:08:09") instead of midnight.
     * whereDate() only normalizes the column, not this bound value, so that
     * leftover time pushes a ">=" lower bound past midnight and silently
     * excludes rows dated exactly on that day. Always pass it through here
     * first so it's a plain Y-m-d string before it reaches a query.
     */
    private static function normalize(mixed $date): string
    {
        return Carbon::parse($date)->toDateString();
    }
}
