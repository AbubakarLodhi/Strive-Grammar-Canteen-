<x-filament-panels::page>
    {{ $this->form }}

    @php($summary = $this->getSummary())

    <div class="mt-4 mb-6">
        <x-filament::card>
            <div class="text-sm text-gray-500">Total expenses</div>
            <div class="text-2xl font-semibold">PKR {{ number_format($summary['grand_total'], 2) }}</div>
        </x-filament::card>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="border-b px-4 py-3 font-medium">By branch</div>
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Branch</th>
                        <th class="px-4 py-2 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($summary['by_branch'] as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="px-4 py-2">{{ $row['label'] }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($row['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="px-4 py-6 text-center text-gray-500">No expenses.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="border-b px-4 py-3 font-medium">Monthly totals</div>
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Month</th>
                        <th class="px-4 py-2 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['by_month'] as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="px-4 py-2">{{ $row['label'] }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($row['total'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
