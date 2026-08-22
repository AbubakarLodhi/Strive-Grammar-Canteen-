<x-filament-widgets::widget>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-slate-900 dark:ring-slate-700/40">
            <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Cash in Hand</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100">PKR {{ number_format((float) $cashBalance, 2) }}</p>
            <p class="mt-1 text-xs text-slate-500">Cash on hand</p>
        </div>
        @forelse ($banks as $bank)
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-slate-900 dark:ring-slate-700/40">
                <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $bank['name'] }}</p>
                @if (filled($bank['account_number'] ?? null))
                    <p class="mt-1 text-xs text-slate-500">A/C {{ $bank['account_number'] }}</p>
                @endif
                <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100">PKR {{ number_format((float) $bank['balance'], 2) }}</p>
                <p class="mt-1 text-xs text-slate-500">Posted deposits and bank receipts</p>
            </div>
        @empty
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-slate-900 dark:ring-slate-700/40">
                <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Bank accounts</p>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Add a bank account under Finance → Bank Accounts.</p>
            </div>
        @endforelse
    </div>
</x-filament-widgets::widget>
