<x-filament-panels::page>
    @php($stats = $this->getPendingStats())

    <div class="mb-4 rounded-xl border border-warning-300 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-600 dark:bg-warning-950 dark:text-warning-200">
        These drafts are not yet in sales totals, cash, or stock. Use <strong>Push</strong> to post them.
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-filament::card>
            <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <x-heroicon-o-clock class="h-4 w-4 text-warning-500" />
                Pending Sales
            </div>
            <div class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">
                {{ $stats['count'] }}
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <x-heroicon-o-currency-dollar class="h-4 w-4 text-success-600" />
                Total Amount
            </div>
            <div class="mt-1 text-2xl font-bold text-success-700 dark:text-success-400">
                PKR&nbsp;{{ number_format($stats['total_amount'], 2) }}
            </div>
        </x-filament::card>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
