<x-filament-panels::page>
    <form wire:submit="import" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center gap-3">
            <x-filament::button type="submit" color="primary" icon="heroicon-o-archive-box">
                Save opening stock
            </x-filament::button>
        </div>
    </form>

    @if ($lastResult)
        @php
            $items = $lastResult['items'] ?? [];
            $sheetProducts = (int) ($lastResult['sheet_products'] ?? count($items));
            $withQty = (int) ($lastResult['products_with_qty'] ?? collect($items)->where('quantity', '>', 0)->count());
            $zeroQty = (int) ($lastResult['products_zero_qty'] ?? max(0, $sheetProducts - $withQty));
            $totalQty = (float) ($lastResult['sheet_total_quantity'] ?? $lastResult['total_quantity'] ?? 0);
            $purchaseValue = (float) ($lastResult['sheet_purchase_value'] ?? 0);
            $sellingValue = (float) ($lastResult['sheet_selling_value'] ?? 0);
            $liveTotal = (float) ($lastResult['live_total_quantity'] ?? $totalQty);
            $addedByPurchases = (float) ($lastResult['added_by_purchases_total'] ?? 0);
            $soldTotal = (float) ($lastResult['sold_total'] ?? 0);
        @endphp

        <div class="mt-6 space-y-6">
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                <h3 class="text-base font-semibold text-slate-900 dark:text-white">Stock totals</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Sheet baseline plus live stock. Current stock = opening stock + later purchases − sales.
                </p>

                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-4">
                    <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                        <dt class="text-slate-500">Total products</dt>
                        <dd class="text-lg font-semibold tabular-nums">{{ number_format($sheetProducts) }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                        <dt class="text-slate-500">Sheet qty</dt>
                        <dd class="text-lg font-semibold tabular-nums">{{ number_format($totalQty) }}</dd>
                    </div>
                    <div class="rounded-lg bg-emerald-50 px-3 py-2 dark:bg-emerald-950/40">
                        <dt class="text-emerald-700 dark:text-emerald-300">Current stock</dt>
                        <dd class="text-lg font-semibold tabular-nums text-emerald-800 dark:text-emerald-200">{{ number_format($liveTotal) }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                        <dt class="text-slate-500">Added by purchases</dt>
                        <dd class="text-lg font-semibold tabular-nums">{{ number_format($addedByPurchases) }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                        <dt class="text-slate-500">Sold</dt>
                        <dd class="text-lg font-semibold tabular-nums">{{ number_format($soldTotal) }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                        <dt class="text-slate-500">Total purchase value</dt>
                        <dd class="text-lg font-semibold tabular-nums">{{ number_format($purchaseValue, 2) }}</dd>
                        <p class="text-xs text-slate-400">Sheet Qty × Pr Price</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                        <dt class="text-slate-500">Total selling value</dt>
                        <dd class="text-lg font-semibold tabular-nums">{{ number_format($sellingValue, 2) }}</dd>
                        <p class="text-xs text-slate-400">Sheet Qty × Sell Price</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                        <dt class="text-slate-500">With qty / Zero qty</dt>
                        <dd class="text-lg font-semibold tabular-nums">{{ number_format($withQty) }} / {{ number_format($zeroQty) }}</dd>
                    </div>
                </dl>
            </div>

            @if (count($items) > 0)
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                    <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                        <h3 class="text-base font-semibold text-slate-900 dark:text-white">All sheet products</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Sheet qty is from the file. Current stock rises when you purchase the same product.
                        </p>
                    </div>

                    <div class="max-h-[36rem] overflow-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                            <thead class="sticky top-0 bg-slate-50 dark:bg-slate-800">
                                <tr class="text-left text-slate-500">
                                    <th class="px-4 py-3 font-medium">#</th>
                                    <th class="px-4 py-3 font-medium">Product Name</th>
                                    <th class="px-4 py-3 text-right font-medium">Sheet qty</th>
                                    <th class="px-4 py-3 text-right font-medium">Added by purchases</th>
                                    <th class="px-4 py-3 text-right font-medium">Sold</th>
                                    <th class="px-4 py-3 text-right font-medium">Current stock</th>
                                    <th class="px-4 py-3 text-right font-medium">Pr Price</th>
                                    <th class="px-4 py-3 text-right font-medium">Sell Price</th>
                                    <th class="px-4 py-3 text-right font-medium">Purchase value</th>
                                    <th class="px-4 py-3 text-right font-medium">Selling value</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($items as $index => $item)
                                    @php
                                        $qty = (float) ($item['quantity'] ?? 0);
                                        $pr = (float) ($item['purchase_price'] ?? 0);
                                        $sell = (float) ($item['selling_price'] ?? 0);
                                        $added = (float) ($item['added_by_purchases'] ?? 0);
                                        $sold = (float) ($item['sold'] ?? 0);
                                        $current = (float) ($item['current_stock'] ?? $qty);
                                    @endphp
                                    <tr class="text-slate-900 dark:text-slate-100">
                                        <td class="px-4 py-2 tabular-nums text-slate-500">{{ $index + 1 }}</td>
                                        <td class="px-4 py-2">{{ $item['name'] ?? '' }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($qty, 2) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($added, 2) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($sold, 2) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums font-semibold">{{ number_format($current, 2) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($pr, 2) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($sell, 2) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($qty * $pr, 2) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($qty * $sell, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="sticky bottom-0 bg-slate-100 font-semibold dark:bg-slate-800">
                                <tr>
                                    <td class="px-4 py-3" colspan="2">Total</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($totalQty, 2) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($addedByPurchases, 2) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($soldTotal, 2) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($liveTotal, 2) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-400">—</td>
                                    <td class="px-4 py-3 text-right text-slate-400">—</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($purchaseValue, 2) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($sellingValue, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <div class="mt-6 rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-600 dark:border-slate-600 dark:text-slate-300">
        <p class="font-semibold text-slate-900 dark:text-white">Expected columns</p>
        <p class="mt-1">Product Name · Qty · Pr Price · Sell Price</p>
        <p class="mt-2">When you purchase a product that is already in opening stock, its current stock increases automatically. Sales reduce it.</p>
    </div>
</x-filament-panels::page>
