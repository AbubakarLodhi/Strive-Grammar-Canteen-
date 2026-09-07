@php
    $format = static fn (float $amount): string => number_format($amount, 2);
@endphp

<div class="fi-ta-ctn divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">
    <div class="overflow-x-auto">
        <table class="w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr class="text-sm text-gray-950 dark:text-white">
                    <th class="px-3 py-3.5 font-semibold sm:first-of-type:ps-6 sm:last-of-type:pe-6">Account</th>
                    <th class="px-3 py-3.5 font-semibold">Type</th>
                    <th class="px-3 py-3.5 font-semibold text-center">Bank</th>
                    <th class="px-3 py-3.5 font-semibold">Account number</th>
                    <th class="px-3 py-3.5 font-semibold text-end">Opening</th>
                    <th class="px-3 py-3.5 font-semibold text-end">Debit</th>
                    <th class="px-3 py-3.5 font-semibold text-end">Credit</th>
                    <th class="px-3 py-3.5 font-semibold text-end">Balance</th>
                    <th class="px-3 py-3.5 font-semibold text-center">Active</th>
                    <th class="px-3 py-3.5 font-semibold sm:last-of-type:pe-6"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                @forelse ($accounts as $account)
                    @php
                        $isVendor = in_array($account['kind'], ['vendor', 'vendor_total'], true);
                        $isTotal = $account['kind'] === 'vendor_total';
                        $rowClass = $isTotal
                            ? 'bg-gray-50 font-semibold dark:bg-white/5'
                            : ($account['indent'] ? 'bg-primary-50/40 dark:bg-primary-400/5' : '');
                    @endphp
                    <tr class="{{ $rowClass }}">
                        <td class="px-3 py-3 text-sm text-gray-950 dark:text-white sm:first-of-type:ps-6">
                            <div class="flex items-center gap-2 {{ $account['indent'] ? 'ps-6' : '' }}">
                                @if ($account['indent'])
                                    <span class="text-gray-400">└</span>
                                @endif
                                <span @class(['font-medium' => ! $isVendor || $isTotal])>
                                    {{ $account['name'] }}
                                </span>
                                @if ($account['kind'] === 'vendor')
                                    <span class="rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                        Vendor
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td class="px-3 py-3 text-sm">
                            @if ($account['type'])
                                <span class="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20">
                                    {{ $account['type'] }}
                                </span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center text-sm">
                            @if ($account['kind'] === 'account')
                                @if ($account['is_bank'])
                                    <x-heroicon-o-check-circle class="mx-auto h-5 w-5 text-success-500" />
                                @else
                                    <x-heroicon-o-x-circle class="mx-auto h-5 w-5 text-danger-500" />
                                @endif
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-sm text-gray-500 dark:text-gray-400">
                            {{ $account['account_number'] ?: '—' }}
                        </td>
                        <td class="px-3 py-3 text-end text-sm tabular-nums text-gray-950 dark:text-white">
                            {{ $format((float) $account['opening']) }}
                        </td>
                        <td class="px-3 py-3 text-end text-sm tabular-nums text-gray-950 dark:text-white">
                            {{ $format((float) $account['debit']) }}
                        </td>
                        <td class="px-3 py-3 text-end text-sm tabular-nums text-gray-950 dark:text-white">
                            {{ $format((float) $account['credit']) }}
                        </td>
                        <td class="px-3 py-3 text-end text-sm tabular-nums font-medium text-gray-950 dark:text-white">
                            {{ $account['balance_label'] }}
                        </td>
                        <td class="px-3 py-3 text-center text-sm">
                            @if ($account['kind'] === 'account')
                                @if ($account['is_active'])
                                    <x-heroicon-o-check-circle class="mx-auto h-5 w-5 text-success-500" />
                                @else
                                    <x-heroicon-o-x-circle class="mx-auto h-5 w-5 text-danger-500" />
                                @endif
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-end sm:last-of-type:pe-6">
                            @if ($account['kind'] === 'account' && $account['edit_url'])
                                <a
                                    href="{{ $account['edit_url'] }}"
                                    class="fi-icon-btn relative flex items-center justify-center rounded-lg text-warning-600 outline-none transition duration-75 hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
                                    title="Edit"
                                >
                                    <x-heroicon-o-pencil-square class="h-5 w-5" />
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            No ledger accounts found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
