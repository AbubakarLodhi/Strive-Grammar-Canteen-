<x-filament-panels::page>
    <form wire:submit="import" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center gap-3">
            <x-filament::button type="submit" color="primary" icon="heroicon-o-arrow-up-tray">
                Import stock sheet
            </x-filament::button>
        </div>
    </form>

    @if ($lastResult)
        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
            <h3 class="text-base font-semibold text-slate-900 dark:text-white">Last import</h3>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-slate-500">Created</dt>
                    <dd class="font-semibold">{{ number_format($lastResult['products_created']) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Updated</dt>
                    <dd class="font-semibold">{{ number_format($lastResult['products_updated']) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Rows</dt>
                    <dd class="font-semibold">{{ number_format($lastResult['rows_imported']) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Total qty</dt>
                    <dd class="font-semibold">{{ number_format($lastResult['total_quantity']) }}</dd>
                </div>
            </dl>
        </div>
    @endif

    <div class="mt-6 rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-600 dark:border-slate-600 dark:text-slate-300">
        <p class="font-semibold text-slate-900 dark:text-white">Expected columns</p>
        <p class="mt-1">Product Name · Qty · Pr Price · Sell Price</p>
        <p class="mt-2">Zero-qty products are still created/updated so the catalog stays complete. Re-upload anytime after go-live to refresh prices and stock.</p>
    </div>
</x-filament-panels::page>
