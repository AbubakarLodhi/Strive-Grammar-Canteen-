<x-filament-panels::page>
    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">From</label>
            <input
                type="date"
                wire:model.live="dateFrom"
                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm dark:border-gray-600 dark:bg-gray-900"
            />
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">To</label>
            <input
                type="date"
                wire:model.live="dateTo"
                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm dark:border-gray-600 dark:bg-gray-900"
            />
        </div>
    </div>

    @if ($account = $this->getSelectedAccount())
        <div class="mb-4 flex items-center justify-between gap-4">
            <div>
                <div class="text-sm text-gray-500">Account ledger</div>
                <div class="text-lg font-semibold">{{ $account->code }} — {{ $account->name }}</div>
                <div class="text-xs text-gray-500">
                    Showing posted lines with running balance
                    @if ($dateFrom || $dateTo)
                        ({{ $dateFrom ?: '…' }} → {{ $dateTo ?: '…' }})
                    @endif
                </div>
            </div>
            <x-filament::button color="gray" wire:click="clearAccount">
                Back to accounts
            </x-filament::button>
        </div>

        <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-gray-500 dark:border-gray-700">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Voucher</th>
                        <th class="px-4 py-3">Description</th>
                        <th class="px-4 py-3 text-right">Debit</th>
                        <th class="px-4 py-3 text-right">Credit</th>
                        <th class="px-4 py-3 text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->ledgerRows as $row)
                        <tr @class([
                            'border-b border-gray-100 dark:border-gray-800',
                            'bg-gray-50 font-medium dark:bg-gray-800/50' => $row->is_opening,
                        ])>
                            <td class="px-4 py-2">{{ optional($row->date)->format('d/m/Y') }}</td>
                            <td class="px-4 py-2">{{ $row->voucher_no }}</td>
                            <td class="px-4 py-2">{{ $row->description }}</td>
                            <td class="px-4 py-2 text-right">{{ $row->is_opening ? '—' : number_format($row->debit, 2) }}</td>
                            <td class="px-4 py-2 text-right">{{ $row->is_opening ? '—' : number_format($row->credit, 2) }}</td>
                            <td class="px-4 py-2 text-right font-medium">{{ number_format($row->balance, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500">No movements in this period.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <p class="mb-4 text-sm text-gray-500">Click an account row (or Open) to view posted transactions for the selected dates.</p>
        {{ $this->table }}
    @endif
</x-filament-panels::page>
