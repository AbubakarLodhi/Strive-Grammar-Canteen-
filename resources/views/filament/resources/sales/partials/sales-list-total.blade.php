@php
    /** @var string|null $label */
@endphp

@if (filled($label))
    <div
        wire:key="sales-list-total"
        class="fi-sales-list-total -mt-2 mb-4 flex items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-900"
    >
        <span class="text-sm text-gray-500 dark:text-gray-400">
            Filtered sales total
        </span>
        <span class="fi-pagination-table-total text-sm font-semibold text-gray-900 whitespace-nowrap dark:text-gray-100">
            {{ $label }}
        </span>
    </div>
@endif
