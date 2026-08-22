<x-filament-widgets::widget>
    @php
        $labels = $trend['labels'] ?? [];
        $salesSeries = $trend['sales'] ?? [];
        $purchaseSeries = $trend['purchases'] ?? [];
        $seriesAll = array_merge($salesSeries, $purchaseSeries);
        $maxValue = max($seriesAll ?: [1]);
        $chartWidth = 560;
        $chartHeight = 260;
        $axisLeft = 36;
        $axisRight = 12;
        $axisTop = 10;
        $axisBottom = 24;
        $plotWidth = $chartWidth - $axisLeft - $axisRight;
        $plotHeight = $chartHeight - $axisTop - $axisBottom;
        $pointCount = max(count($salesSeries), 1);
        $step = $pointCount > 1 ? $plotWidth / ($pointCount - 1) : 0;
        $makePoints = function (array $series) use ($axisLeft, $axisTop, $plotHeight, $pointCount, $step, $maxValue) {
            $points = [];
            foreach ($series as $index => $value) {
                $x = $axisLeft + ($index * $step);
                $normalized = $maxValue > 0 ? $value / $maxValue : 0;
                $y = $axisTop + ($plotHeight - ($plotHeight * $normalized));
                $points[] = $x . ',' . $y;
            }
            return implode(' ', $points);
        };
        $salesPoints = $makePoints($salesSeries);
        $purchasePoints = $makePoints($purchaseSeries);
        $periodSalesTotal = array_sum($salesSeries);
        $periodPurchasesTotal = array_sum($purchaseSeries);
        $leaders = $leaders ?? ['customers' => [], 'vendors' => [], 'variants' => []];
        $credit = $credit ?? ['receivable_total' => 0, 'payable_total' => 0, 'top_customers' => [], 'top_vendors' => []];
        $expenses = $expenses ?? ['total_expenses' => 0, 'total_amount' => 0, 'avg_expense' => 0];
        $funds = $funds ?? ['opening_total_funds' => 0, 'sales_cash_inflow' => 0, 'purchases_cash_outflow' => 0, 'expenses_outflow' => 0, 'payroll_outflow' => 0, 'cash_flow_net' => 0, 'cash_flow_received' => 0, 'cash_flow_paid' => 0, 'cash_flow_receivable' => 0, 'cash_flow_payable' => 0, 'net_cash_movement' => 0, 'current_total_funds' => 0, 'cash_ledger' => 0, 'ubl_ledger' => 0];
        $profitLoss = $profitLoss ?? ['gross_profit' => 0, 'net_profit' => 0, 'net_sales' => 0, 'net_purchases' => 0, 'expenses' => 0, 'payrolls' => 0, 'sales_returns' => 0, 'purchase_returns' => 0];
        $filterPeriodLabel = $filterPeriodLabel ?? 'All time';
        $barMax = max($seriesAll ?: [1]);
        $barHeights = [
            'sales' => array_map(fn ($value) => $barMax > 0 ? (int) round(($value / $barMax) * 100) : 0, $salesSeries),
            'purchases' => array_map(fn ($value) => $barMax > 0 ? (int) round(($value / $barMax) * 100) : 0, $purchaseSeries),
        ];
        $tickCount = 4;
        $ticks = [];
        for ($i = 0; $i <= $tickCount; $i++) {
            $ticks[] = [
                'value' => (int) round($maxValue * (1 - ($i / $tickCount))),
                'y' => $axisTop + ($plotHeight * ($i / $tickCount)),
            ];
        }
    @endphp

    <div class="space-y-6 stats-dashboard" x-data="{ showSales: true, showPurchases: true, profitLossMetric: 'gross_profit', toggle(which) { if (which === 'sales') { this.showSales = !this.showSales; if (!this.showSales && !this.showPurchases) this.showPurchases = true; } if (which === 'purchases') { this.showPurchases = !this.showPurchases; if (!this.showPurchases && !this.showSales) this.showSales = true; } } }">
        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 stats-card dark:bg-slate-900 dark:text-slate-100 dark:ring-slate-700/40">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">Overview</p>
                <div class="flex flex-wrap items-center gap-3">
                    <div x-data="{ open: false }" class="relative" @keydown.escape.window="open = false">
                        <button
                            type="button"
                            @click="open = !open"
                            class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 transition hover:bg-slate-200/80 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-700"
                            aria-haspopup="listbox"
                            :aria-expanded="open"
                        >
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" />
                            </svg>
                            <span>{{ $overviewModulesFilterLabel }}</span>
                            <svg class="h-3.5 w-3.5 transition" :class="open ? 'rotate-180' : ''" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>
                        <div
                            x-show="open"
                            x-transition
                            @click.outside="open = false"
                            x-cloak
                            class="absolute right-0 z-20 mt-2 w-60 rounded-xl bg-white p-3 shadow-lg ring-1 ring-gray-950/10 dark:bg-slate-900 dark:ring-slate-700"
                            role="listbox"
                            aria-label="Overview modules"
                        >
                            <p class="px-1 pb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Show summaries</p>
                            <div class="max-h-64 space-y-1 overflow-y-auto">
                                @foreach ($overviewModuleOptions as $value => $label)
                                    <label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800">
                                        <input
                                            type="checkbox"
                                            wire:model.live="overviewModules"
                                            value="{{ $value }}"
                                            class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800"
                                        >
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @if (count($overviewModules) > 0)
                                <button
                                    type="button"
                                    wire:click="clearOverviewModules"
                                    class="mt-2 w-full rounded-lg px-2 py-1.5 text-left text-xs font-semibold text-primary-600 hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-950/40"
                                >
                                    Show all modules
                                </button>
                            @endif
                        </div>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-200">{{ $filterPeriodLabel }}</span>
                </div>
            </div>
            <div class="mt-6 space-y-6">
                @foreach ($overviewLayoutRows as $row)
                    <div class="{{ $row['classes'] }}">
                        @foreach ($row['modules'] as $module)
                            @include('filament.widgets.partials.overview-module-card', ['module' => $module])
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 stats-card dark:bg-slate-900 dark:text-slate-100 dark:ring-slate-700/40">
            <div class="flex items-center justify-between">
                <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">Top Performers</p>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-200">Top 3</span>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="rounded-2xl bg-gradient-to-br from-blue-50 to-white p-5 shadow-sm ring-1 ring-blue-100 dark:from-slate-950 dark:to-slate-950 dark:ring-blue-900/40">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Customers by Sales</p>
                        <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-600 dark:bg-blue-900/40 dark:text-blue-200">Sales</span>
                    </div>
                    <div class="mt-4 space-y-3">
                        @forelse (($leaders['customers'] ?? []) as $index => $row)
                            <div class="rounded-xl bg-white p-3 ring-1 ring-slate-100 dark:bg-slate-800/80 dark:ring-slate-700/40">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-start gap-3">
                                        <span class="mt-0.5 inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">{{ $index + 1 }}</span>
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $row['name'] }}</p>
                                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ number_format($row['count']) }} sales</p>
                                        </div>
                                    </div>
                                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ number_format($row['amount'], 2) }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500 dark:text-slate-400">No customer sales data available.</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-2xl bg-gradient-to-br from-emerald-50 to-white p-5 shadow-sm ring-1 ring-emerald-100 dark:from-slate-950 dark:to-slate-950 dark:ring-emerald-900/40">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Vendors by Purchases</p>
                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-200">Purchases</span>
                    </div>
                    <div class="mt-4 space-y-3">
                        @forelse (($leaders['vendors'] ?? []) as $index => $row)
                            <div class="rounded-xl bg-white p-3 ring-1 ring-slate-100 dark:bg-slate-800/80 dark:ring-slate-700/40">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-start gap-3">
                                        <span class="mt-0.5 inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">{{ $index + 1 }}</span>
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $row['name'] }}</p>
                                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ number_format($row['count']) }} purchases</p>
                                        </div>
                                    </div>
                                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ number_format($row['amount'], 2) }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500 dark:text-slate-400">No vendor purchase data available.</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-2xl bg-gradient-to-br from-amber-50 to-white p-5 shadow-sm ring-1 ring-amber-100 dark:from-slate-950 dark:to-slate-950 dark:ring-amber-900/40">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Top Variants Sold</p>
                        <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-600 dark:bg-amber-900/40 dark:text-amber-200">Products</span>
                    </div>
                    <div class="mt-4 space-y-3">
                        @forelse (($leaders['variants'] ?? []) as $index => $row)
                            <div class="rounded-xl bg-white p-3 ring-1 ring-slate-100 dark:bg-slate-800/80 dark:ring-slate-700/40">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-start gap-3">
                                        <span class="mt-0.5 inline-flex h-6 w-6 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-700 dark:bg-amber-900/40 dark:text-amber-200">{{ $index + 1 }}</span>
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $row['name'] }}</p>
                                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $row['product'] }} • SKU: {{ $row['sku'] }}</p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ number_format($row['qty'], 2) }}</p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">qty sold</p>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500 dark:text-slate-400">No product variant sales data available.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 stats-card dark:bg-slate-900 dark:text-slate-100 dark:ring-slate-700/40">
            <div class="flex items-center justify-between">
                <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">Credit Overview</p>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-200">Outstanding</span>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-4">
                <div class="rounded-2xl bg-gradient-to-br from-blue-50 to-white p-5 shadow-sm ring-1 ring-blue-100 stats-panel stats-panel-blue dark:from-slate-950 dark:to-slate-950 dark:ring-blue-900/40 lg:col-span-2">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Receivable from Customers</p>
                        <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-600 dark:bg-blue-900/40 dark:text-blue-200">Sales Credit</span>
                    </div>
                    <p class="mt-4 text-3xl font-semibold text-slate-900 dark:text-slate-100">{{ number_format($credit['receivable_total'], 2) }}</p>
                    <div class="mt-4 space-y-3">
                        @forelse (($credit['top_customers'] ?? []) as $index => $row)
                            <div class="rounded-xl bg-white p-3 ring-1 ring-slate-100 dark:bg-slate-800/80 dark:ring-slate-700/40">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-start gap-3">
                                        <span class="mt-0.5 inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">{{ $index + 1 }}</span>
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $row['name'] }}</p>
                                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ number_format($row['count']) }} credit sales</p>
                                        </div>
                                    </div>
                                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ number_format($row['amount'], 2) }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500 dark:text-slate-400">No customer credit data available.</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-2xl bg-gradient-to-br from-emerald-50 to-white p-5 shadow-sm ring-1 ring-emerald-100 stats-panel stats-panel-emerald dark:from-slate-950 dark:to-slate-950 dark:ring-emerald-900/40 lg:col-span-2">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Payable to Vendors</p>
                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-200">Purchase Credit</span>
                    </div>
                    <p class="mt-4 text-3xl font-semibold text-slate-900 dark:text-slate-100">{{ number_format($credit['payable_total'], 2) }}</p>
                    <div class="mt-4 space-y-3">
                        @forelse (($credit['top_vendors'] ?? []) as $index => $row)
                            <div class="rounded-xl bg-white p-3 ring-1 ring-slate-100 dark:bg-slate-800/80 dark:ring-slate-700/40">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-start gap-3">
                                        <span class="mt-0.5 inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">{{ $index + 1 }}</span>
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $row['name'] }}</p>
                                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ number_format($row['count']) }} credit purchases</p>
                                        </div>
                                    </div>
                                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ number_format($row['amount'], 2) }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500 dark:text-slate-400">No vendor credit data available.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 stats-card dark:bg-slate-900 dark:text-slate-100 dark:ring-slate-700/40">
            <div class="flex items-center justify-between">
                <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">Business Pulse</p>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-200">Last 6 months</span>
            </div>
            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-4">
                <div class="lg:col-span-3">
                    <div class="rounded-2xl bg-gradient-to-br from-blue-50 via-white to-emerald-50 p-6 ring-1 ring-gray-950/5 stats-panel dark:bg-slate-900 dark:ring-slate-700/40">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Sales vs Purchases</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Monthly trend</p>
                            </div>
                            <div class="flex items-center gap-2 text-xs text-gray-500">
                                <button type="button" class="flex items-center gap-2" @click="toggle('sales')" :class="showSales ? 'opacity-100' : 'opacity-40'">
                                    <span class="h-2 w-2 rounded-full bg-blue-500"></span>Sales
                                </button>
                                <button type="button" class="flex items-center gap-2" @click="toggle('purchases')" :class="showPurchases ? 'opacity-100' : 'opacity-40'">
                                    <span class="h-2 w-2 rounded-full bg-emerald-400"></span>Purchases
                                </button>
                            </div>
                        </div>

                        <div class="mt-4 overflow-hidden rounded-xl bg-white/80 p-4 shadow-sm dark:bg-slate-900/80">
                            <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" class="h-64 w-full">
                                @foreach ($ticks as $tick)
                                    <line x1="{{ $axisLeft }}" x2="{{ $chartWidth - $axisRight }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" stroke="#e2e8f0" stroke-width="1"></line>
                                    <text x="2" y="{{ $tick['y'] + 4 }}" font-size="10" fill="#64748b">{{ number_format($tick['value']) }}</text>
                                @endforeach
                                <polyline points="{{ $purchasePoints }}" fill="none" stroke="#34d399" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" x-show="showPurchases"></polyline>
                                <polyline points="{{ $salesPoints }}" fill="none" stroke="#3b82f6" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" x-show="showSales"></polyline>
                                @foreach ($salesSeries as $index => $value)
                                    @php
                                        $x = $axisLeft + ($index * $step);
                                        $normalized = $maxValue > 0 ? $value / $maxValue : 0;
                                        $y = $axisTop + ($plotHeight - ($plotHeight * $normalized));
                                    @endphp
                                    <circle cx="{{ $x }}" cy="{{ $y }}" r="3" fill="#3b82f6" x-show="showSales"></circle>
                                    <text x="{{ $x }}" y="{{ $y - 6 }}" text-anchor="middle" font-size="10" fill="#1e293b" x-show="showSales">{{ number_format($value) }}</text>
                                @endforeach
                                @foreach ($purchaseSeries as $index => $value)
                                    @php
                                        $x = $axisLeft + ($index * $step);
                                        $normalized = $maxValue > 0 ? $value / $maxValue : 0;
                                        $y = $axisTop + ($plotHeight - ($plotHeight * $normalized));
                                    @endphp
                                    <circle cx="{{ $x }}" cy="{{ $y }}" r="3" fill="#34d399" x-show="showPurchases"></circle>
                                    <text x="{{ $x }}" y="{{ $y - 6 }}" text-anchor="middle" font-size="10" fill="#047857" x-show="showPurchases">{{ number_format($value) }}</text>
                                @endforeach

                                @foreach ($labels as $index => $label)
                                    @php $x = $axisLeft + ($index * $step); @endphp
                                    <text x="{{ $x }}" y="{{ $chartHeight - 6 }}" text-anchor="middle" font-size="10" fill="#64748b">{{ $label }}</text>
                                @endforeach
                            </svg>
                        </div>

                        <div class="mt-4 rounded-xl bg-white/80 p-4 shadow-sm dark:bg-slate-900/80">
                            <div class="flex items-center justify-between text-xs text-slate-600 dark:text-slate-300">
                                <span class="font-semibold text-slate-700 dark:text-slate-100">Monthly Volume (Bar)</span>
                                <div class="flex items-center gap-4">
                                    <span class="text-slate-500 dark:text-slate-400">Counts</span>
                                    <button type="button" class="flex items-center gap-2" @click="toggle('sales')" :class="showSales ? 'opacity-100' : 'opacity-40'">
                                        <span class="h-2 w-2 rounded-full bg-blue-500"></span>Sales
                                    </button>
                                    <button type="button" class="flex items-center gap-2" @click="toggle('purchases')" :class="showPurchases ? 'opacity-100' : 'opacity-40'">
                                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>Purchases
                                    </button>
                                </div>
                            </div>
                            <div class="mt-4 grid grid-cols-6 gap-3">
                                @foreach ($labels as $index => $label)
                                    <div class="flex flex-col items-center gap-2">
                                        <div class="relative flex h-48 items-end gap-1">
                                            <div class="relative w-3 rounded-full bg-blue-500" style="height: {{ $barHeights['sales'][$index] ?? 0 }}%;" x-show="showSales">
                                                <span class="absolute -top-5 left-1/2 -translate-x-1/2 text-[10px] font-semibold text-slate-700">{{ number_format($salesSeries[$index] ?? 0) }}</span>
                                            </div>
                                            <div class="relative w-3 rounded-full bg-emerald-500" style="height: {{ $barHeights['purchases'][$index] ?? 0 }}%;" x-show="showPurchases">
                                                <span class="absolute -top-5 left-1/2 -translate-x-1/2 text-[10px] font-semibold text-slate-700">{{ number_format($purchaseSeries[$index] ?? 0) }}</span>
                                            </div>
                                        </div>
                                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ $label }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>


                    </div>
                </div>

                <div class="space-y-4">
                    <div class="rounded-xl bg-slate-900 p-5 text-white shadow-sm stats-dark-card">
                        <p class="text-xs uppercase tracking-wide text-slate-300">Sales Count</p>
                        <p class="mt-2 text-2xl font-semibold">{{ number_format($periodSalesTotal) }}</p>
                        <p class="mt-1 text-xs text-slate-300">All sales in the period</p>
                    </div>
                    <div class="rounded-xl bg-emerald-600 p-5 text-white shadow-sm stats-success-card">
                        <p class="text-xs uppercase tracking-wide text-emerald-100">Purchases Count</p>
                        <p class="mt-2 text-2xl font-semibold">{{ number_format($periodPurchasesTotal) }}</p>
                        <p class="mt-1 text-xs text-emerald-100">All purchases in the period</p>
                    </div>
                    <div class="rounded-xl bg-gradient-to-br from-slate-50 to-white p-5 shadow-sm ring-1 ring-gray-950/5 stats-panel dark:bg-slate-900 dark:ring-slate-700/40">
                        <p class="text-xs uppercase tracking-wide text-slate-700 dark:text-slate-200">Inventory Health</p>
                        <div class="mt-3 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                            <div class="flex items-center justify-between">
                                <span>Available Stock</span>
                                <span class="font-semibold text-slate-900 dark:text-slate-100">{{ number_format($stock['available_stock'], 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Sold Qty</span>
                                <span class="font-semibold text-slate-900 dark:text-slate-100">{{ number_format($stock['total_sold_qty'], 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Purchased Qty</span>
                                <span class="font-semibold text-slate-900 dark:text-slate-100">{{ number_format($stock['total_purchased_qty'], 2) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl bg-gradient-to-br from-rose-50 to-white p-5 shadow-sm ring-1 ring-rose-100 stats-panel stats-panel-rose dark:bg-slate-900 dark:ring-rose-900/50">
                        <div class="flex items-center justify-between">
                            <p class="text-xs uppercase tracking-wide text-rose-600">Sale Returns</p>
                            <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-600">Sales</span>
                        </div>
                        <div class="mt-3 space-y-2 text-sm text-slate-700">
                            <div class="flex items-center justify-between">
                                <span>Total Returns</span>
                                <span class="font-semibold text-slate-900">{{ number_format($returns['sales']['total_returns']) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Returned Qty</span>
                                <span class="font-semibold text-slate-900">{{ number_format($returns['sales']['total_quantity'], 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Total Amount</span>
                                <span class="font-semibold text-slate-900">{{ number_format($returns['sales']['total_amount'], 2) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl bg-gradient-to-br from-amber-50 to-white p-5 shadow-sm ring-1 ring-amber-100 stats-panel stats-panel-amber dark:bg-slate-900 dark:ring-amber-900/50">
                        <div class="flex items-center justify-between">
                            <p class="text-xs uppercase tracking-wide text-amber-600">Purchase Returns</p>
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-600">Purchases</span>
                        </div>
                        <div class="mt-3 space-y-2 text-sm text-slate-700">
                            <div class="flex items-center justify-between">
                                <span>Total Returns</span>
                                <span class="font-semibold text-slate-900">{{ number_format($returns['purchases']['total_returns']) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Returned Qty</span>
                                <span class="font-semibold text-slate-900">{{ number_format($returns['purchases']['total_quantity'], 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Total Amount</span>
                                <span class="font-semibold text-slate-900">{{ number_format($returns['purchases']['total_amount'], 2) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</x-filament-widgets::widget>
