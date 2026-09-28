<x-filament-panels::page>
    {{ $this->form }}

    <div class="mt-6 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-500">
                <tr>
                    <th class="px-4 py-3">Month</th>
                    <th class="px-4 py-3 text-right">Sales</th>
                    <th class="px-4 py-3 text-right">Purchases</th>
                    <th class="px-4 py-3 text-right">Expenses</th>
                    <th class="px-4 py-3 text-right">Net</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->getRows() as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-2">{{ $row['month'] }}</td>
                        <td class="px-4 py-2 text-right">{{ number_format($row['sales'], 2) }}</td>
                        <td class="px-4 py-2 text-right">{{ number_format($row['purchases'], 2) }}</td>
                        <td class="px-4 py-2 text-right">{{ number_format($row['expenses'], 2) }}</td>
                        <td class="px-4 py-2 text-right font-medium">{{ number_format($row['net'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
