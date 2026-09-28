<x-filament-panels::page>
    {{ $this->form }}

    <div class="mt-4 mb-2 grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-filament::card>
            <div class="text-xs text-gray-500">Lines</div>
            <div class="text-lg font-semibold">{{ $this->rows->count() }}</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-xs text-gray-500">Total debit</div>
            <div class="text-lg font-semibold">{{ number_format($this->debitTotal(), 2) }}</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-xs text-gray-500">Total credit</div>
            <div class="text-lg font-semibold">{{ number_format($this->creditTotal(), 2) }}</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-xs text-gray-500">Difference</div>
            <div class="text-lg font-semibold">{{ number_format($this->debitTotal() - $this->creditTotal(), 2) }}</div>
        </x-filament::card>
    </div>

    <div class="mt-4 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-500">
                <tr>
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Voucher</th>
                    <th class="px-4 py-3">Account</th>
                    <th class="px-4 py-3">Description</th>
                    <th class="px-4 py-3 text-right">Debit</th>
                    <th class="px-4 py-3 text-right">Credit</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->rows as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-2">{{ optional($row->date)->format('d/m/Y') }}</td>
                        <td class="px-4 py-2">{{ $row->voucher_no }}</td>
                        <td class="px-4 py-2">{{ $row->account }}</td>
                        <td class="px-4 py-2">{{ $row->description }}</td>
                        <td class="px-4 py-2 text-right">{{ number_format($row->debit, 2) }}</td>
                        <td class="px-4 py-2 text-right">{{ number_format($row->credit, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">No posted journal lines in this period.</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($this->rows->isNotEmpty())
                <tfoot>
                    <tr class="border-t font-semibold">
                        <td colspan="4" class="px-4 py-3">Total</td>
                        <td class="px-4 py-3 text-right">{{ number_format($this->debitTotal(), 2) }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($this->creditTotal(), 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</x-filament-panels::page>
