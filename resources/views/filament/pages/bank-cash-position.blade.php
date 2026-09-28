<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->getAccounts() as $account)
            <x-filament::card>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $account['is_cash'] ? 'Cash' : 'Bank' }}
                </div>
                <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $account['name'] }}</div>
                <div class="mt-3 text-2xl font-semibold">PKR {{ number_format((float) $account['balance'], 2) }}</div>
            </x-filament::card>
        @empty
            <div class="text-gray-500">No cash or bank accounts found.</div>
        @endforelse
    </div>
</x-filament-panels::page>
