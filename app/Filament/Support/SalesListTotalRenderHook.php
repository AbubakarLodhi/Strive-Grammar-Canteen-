<?php

namespace App\Filament\Support;

use App\Filament\Resources\Sales\Pages\ListSales;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Livewire\Livewire;

class SalesListTotalRenderHook
{
    public static function register(Panel $panel): Panel
    {
        return $panel->renderHook(
            PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER,
            function (): View|string {
                $livewire = Livewire::current();

                if (! $livewire instanceof ListSales) {
                    return '';
                }

                $label = $livewire->getTablePaginationTotalLabel();

                if (blank($label)) {
                    return '';
                }

                return view('filament.resources.sales.partials.sales-list-total', [
                    'label' => $label,
                ]);
            },
            scopes: ListSales::class,
        );
    }
}
