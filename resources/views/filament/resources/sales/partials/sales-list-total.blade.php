@php
    /** @var string $label */
@endphp

<div
    wire:key="sales-list-total-{{ md5($label) }}"
    class="fi-sales-list-total flex justify-end px-4 py-2"
    x-data="{
        place() {
            const label = this.$refs.total
            const ctn = document.querySelector('.fi-pagination-records-per-page-select-ctn')

            if (! label || ! ctn) {
                return
            }

            ctn.classList.add('flex', 'items-center', 'gap-3')

            if (label.parentElement !== ctn) {
                ctn.prepend(label)
            }

            this.$el.classList.add('hidden')
        },
    }"
    x-init="
        place()
        $nextTick(() => place())
        Livewire.hook('morph.updated', () => place())
    "
>
    <span
        x-ref="total"
        class="fi-pagination-table-total text-sm font-semibold text-gray-700 whitespace-nowrap dark:text-gray-200"
    >
        {{ $label }}
    </span>
</div>
