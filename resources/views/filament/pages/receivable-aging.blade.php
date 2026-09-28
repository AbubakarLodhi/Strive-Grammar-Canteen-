<x-filament-panels::page>
    @php($data = $this->getAgingData())

    <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
        <x-filament::card>
            <div class="text-xs text-gray-500">Not due</div>
            <div class="text-lg font-semibold">{{ number_format($data['buckets']['current'], 2) }}</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-xs text-gray-500">1–30 days</div>
            <div class="text-lg font-semibold">{{ number_format($data['buckets']['1_30'], 2) }}</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-xs text-gray-500">31–60 days</div>
            <div class="text-lg font-semibold">{{ number_format($data['buckets']['31_60'], 2) }}</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-xs text-gray-500">61–90 days</div>
            <div class="text-lg font-semibold">{{ number_format($data['buckets']['61_90'], 2) }}</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-xs text-gray-500">90+ days</div>
            <div class="text-lg font-semibold text-danger-600">{{ number_format($data['buckets']['90_plus'], 2) }}</div>
        </x-filament::card>
        <x-filament::card>
            <div class="text-xs text-gray-500">Overdue total</div>
            <div class="text-lg font-semibold text-danger-600">{{ number_format($data['overdue_total'], 2) }}</div>
        </x-filament::card>
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-500">
                <tr>
                    <th class="px-4 py-3">Sale</th>
                    <th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3">Due date</th>
                    <th class="px-4 py-3 text-right">Days overdue</th>
                    <th class="px-4 py-3 text-right">Due amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($data['rows'] as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-2">
                            <a href="{{ $row['url'] }}" class="text-primary-600 hover:underline">{{ $row['sale_no'] }}</a>
                        </td>
                        <td class="px-4 py-2">{{ $row['customer'] }}</td>
                        <td class="px-4 py-2">{{ $row['due_date'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['days_overdue'] }}</td>
                        <td class="px-4 py-2 text-right">{{ number_format($row['due_amount'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-500">No open receivables.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
