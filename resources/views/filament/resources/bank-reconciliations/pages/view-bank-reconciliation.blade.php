<x-filament-panels::page>
    @php($totals = $this->getTotals())

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-filament::card>
            <div class="text-sm text-gray-500 dark:text-gray-400">Statement opening</div>
            <div class="mt-1 text-xl font-semibold">PKR {{ number_format((float) $this->record->statement_opening, 2) }}</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-sm text-gray-500 dark:text-gray-400">Matched net</div>
            <div class="mt-1 text-xl font-semibold">PKR {{ number_format($totals['matched_net'], 2) }}</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-sm text-gray-500 dark:text-gray-400">Expected closing</div>
            <div class="mt-1 text-xl font-semibold">PKR {{ number_format($totals['expected_closing'], 2) }}</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-sm text-gray-500 dark:text-gray-400">Difference</div>
            <div @class([
                'mt-1 text-xl font-semibold',
                'text-success-600' => abs($totals['difference']) < 0.01,
                'text-danger-600' => abs($totals['difference']) >= 0.01,
            ])>
                PKR {{ number_format($totals['difference'], 2) }}
            </div>
            <div class="text-xs text-gray-400">Statement closing: PKR {{ number_format((float) $this->record->statement_closing, 2) }}</div>
        </x-filament::card>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
