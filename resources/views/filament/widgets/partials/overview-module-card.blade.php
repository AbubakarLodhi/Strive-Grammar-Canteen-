@switch ($module)
    @case ('sales')
        <div class="rounded-2xl bg-gradient-to-br from-blue-50 to-white p-5 shadow-sm ring-1 ring-blue-100 stats-panel stats-panel-blue dark:bg-slate-900 dark:ring-blue-900/50">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Sales Summary</p>
                <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-600 dark:bg-blue-900/40 dark:text-blue-200">Sales</span>
            </div>
            <p class="mt-4 text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ number_format($sales['total_amount'], 2) }}</p>
            <div class="mt-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                <div class="flex items-center justify-between">
                    <span>Total Sales</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($sales['total_sales']) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Quantity Sold</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($sales['total_quantity'], 2) }}</span>
                </div>
            </div>
        </div>
        @break

    @case ('purchases')
        <div class="rounded-2xl bg-gradient-to-br from-emerald-50 to-white p-5 shadow-sm ring-1 ring-emerald-100 stats-panel stats-panel-emerald dark:bg-slate-900 dark:ring-emerald-900/50">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Purchases Summary</p>
                <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-200">Purchases</span>
            </div>
            <p class="mt-4 text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ number_format($purchases['total_amount'], 2) }}</p>
            <div class="mt-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                <div class="flex items-center justify-between">
                    <span>Total Purchases</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($purchases['total_purchases']) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Quantity Bought</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($purchases['total_items_quantity'], 2) }}</span>
                </div>
            </div>
        </div>
        @break

    @case ('profit_loss')
        <div class="rounded-2xl bg-gradient-to-br from-lime-50 to-white p-5 shadow-sm ring-1 ring-lime-100 stats-panel dark:bg-slate-900 dark:ring-lime-900/50">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Profit Loss</p>
                <select
                    x-model="profitLossMetric"
                    class="rounded-full border-0 bg-lime-50 px-3 py-1 text-xs font-semibold text-lime-700 shadow-none ring-0 focus:ring-2 focus:ring-lime-200 dark:bg-lime-900/40 dark:text-lime-200"
                    aria-label="Profit Loss headline metric"
                >
                    <option value="gross_profit">Gross Profit</option>
                    <option value="net_profit">Net Profit</option>
                    <option value="net_sales">Net Sales</option>
                    <option value="net_purchases">Net Purchases</option>
                </select>
            </div>
            <p class="mt-4 text-2xl font-semibold">
                <span x-show="profitLossMetric === 'gross_profit'" class="{{ ($profitLoss['gross_profit'] ?? 0) < 0 ? 'text-rose-600 dark:text-rose-300' : 'text-slate-900 dark:text-slate-100' }}">PKR {{ number_format($profitLoss['gross_profit'] ?? 0, 2) }}</span>
                <span x-show="profitLossMetric === 'net_profit'" class="{{ ($profitLoss['net_profit'] ?? 0) < 0 ? 'text-rose-600 dark:text-rose-300' : 'text-slate-900 dark:text-slate-100' }}">PKR {{ number_format($profitLoss['net_profit'] ?? 0, 2) }}</span>
                <span x-show="profitLossMetric === 'net_sales'" class="{{ ($profitLoss['net_sales'] ?? 0) < 0 ? 'text-rose-600 dark:text-rose-300' : 'text-slate-900 dark:text-slate-100' }}">PKR {{ number_format($profitLoss['net_sales'] ?? 0, 2) }}</span>
                <span x-show="profitLossMetric === 'net_purchases'" class="{{ ($profitLoss['net_purchases'] ?? 0) < 0 ? 'text-rose-600 dark:text-rose-300' : 'text-slate-900 dark:text-slate-100' }}">PKR {{ number_format($profitLoss['net_purchases'] ?? 0, 2) }}</span>
            </p>
            <div class="mt-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                <div class="flex items-center justify-between">
                    <span class="inline-flex items-center gap-1">
                        Gross Profit
                        <span
                            class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-500 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700"
                            title="Gross Profit = Net Sales - Net Purchases."
                            aria-label="Gross Profit formula"
                        >?</span>
                    </span>
                    <span class="font-medium {{ ($profitLoss['gross_profit'] ?? 0) < 0 ? 'text-rose-600 dark:text-rose-300' : 'text-slate-900 dark:text-slate-100' }}">PKR {{ number_format($profitLoss['gross_profit'] ?? 0, 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="inline-flex items-center gap-1">
                        Net Profit
                        <span
                            class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-500 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700"
                            title="Net Profit = Gross Profit - Expenses - Payrolls"
                            aria-label="Net Profit formula"
                        >?</span>
                    </span>
                    <span class="font-medium {{ ($profitLoss['net_profit'] ?? 0) < 0 ? 'text-rose-600 dark:text-rose-300' : 'text-slate-900 dark:text-slate-100' }}">PKR {{ number_format($profitLoss['net_profit'] ?? 0, 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Net Sales</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">PKR {{ number_format($profitLoss['net_sales'] ?? 0, 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Net Purchases</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">PKR {{ number_format($profitLoss['net_purchases'] ?? 0, 2) }}</span>
                </div>
            </div>
        </div>
        @break

    @case ('stock')
        <div class="rounded-2xl bg-gradient-to-br from-orange-50 to-white p-5 shadow-sm ring-1 ring-orange-100 stats-panel stats-panel-orange dark:bg-slate-900 dark:ring-orange-900/50">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Stock Summary</p>
                <span class="rounded-full bg-orange-50 px-3 py-1 text-xs font-semibold text-orange-600 dark:bg-orange-900/40 dark:text-orange-200">Stock</span>
            </div>
            <p class="mt-4 text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ number_format($stock['available_stock'], 2) }}</p>
            <div class="mt-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                <div class="flex items-center justify-between">
                    <span>Total Products</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($stock['total_products']) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Total Revenue</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($stock['total_revenue'], 2) }}</span>
                </div>
            </div>
        </div>
        @break

    @case ('inventory_movement')
        <div class="rounded-2xl bg-gradient-to-br from-sky-50 to-white p-5 shadow-sm ring-1 ring-sky-100 stats-panel stats-panel-sky dark:bg-slate-900 dark:ring-sky-900/50">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Inventory Movement</p>
                <span class="rounded-full bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-600 dark:bg-sky-900/40 dark:text-sky-200">{{ $filterPeriodLabel }}</span>
            </div>
            <p class="mt-4 text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ number_format($stock['total_purchased_qty'] - $stock['total_sold_qty'], 2) }}</p>
            <div class="mt-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                <div class="flex items-center justify-between">
                    <span>Inbound Qty</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($stock['total_purchased_qty'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Outbound Qty</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($stock['total_sold_qty'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Available Stock</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($stock['available_stock'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Total Amount</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">PKR {{ number_format($stock['total_amount'] ?? 0, 2) }}</span>
                </div>
            </div>
        </div>
        @break

    @case ('expenses')
        <div class="rounded-2xl bg-gradient-to-br from-rose-50 to-white p-5 shadow-sm ring-1 ring-rose-100 stats-panel dark:bg-slate-900 dark:ring-rose-900/50">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Expenses Summary</p>
                <span class="rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-600 dark:bg-rose-900/40 dark:text-rose-200">Expenses</span>
            </div>
            <p class="mt-4 text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ number_format($expenses['total_amount'], 2) }}</p>
            <div class="mt-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                <div class="flex items-center justify-between">
                    <span>Total Expenses</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($expenses['total_expenses']) }}</span>
                </div>
            </div>
        </div>
        @break

    @case ('funds')
        <div class="rounded-2xl bg-gradient-to-br from-indigo-50 to-white p-5 shadow-sm ring-1 ring-indigo-100 stats-panel dark:bg-slate-900 dark:ring-indigo-900/50">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Total Funds</p>
                <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-200">Cash Pool</span>
            </div>
            <p class="mt-4 text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ number_format($funds['current_total_funds'], 2) }}</p>
            <div class="mt-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                <div class="flex items-center justify-between">
                    <span>Cash in Hand (ledger)</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($funds['cash_ledger'] ?? 0, 2) }}</span>
                </div>
                @forelse ($funds['bank_ledgers'] ?? [] as $bank)
                <div class="flex items-center justify-between">
                    <span>{{ $bank['name'] }} (ledger)</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($bank['balance'], 2) }}</span>
                </div>
                @empty
                <div class="flex items-center justify-between">
                    <span>UBL Bank (ledger)</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($funds['ubl_ledger'] ?? 0, 2) }}</span>
                </div>
                @endforelse
                <div class="flex items-center justify-between">
                    <span>Opening Funds</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($funds['opening_total_funds'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Sales Cash In</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($funds['sales_cash_inflow'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Purchases Cash Out</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($funds['purchases_cash_outflow'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Expenses Out</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($funds['expenses_outflow'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Payroll Out</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($funds['payroll_outflow'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Cash Flow Net</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($funds['cash_flow_net'], 2) }}</span>
                </div>
            </div>
        </div>
        @break

    @case ('cash_flow')
        <div class="rounded-2xl bg-gradient-to-br from-cyan-50 to-white p-5 shadow-sm ring-1 ring-cyan-100 stats-panel dark:bg-slate-900 dark:ring-cyan-900/50">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Cash Flow</p>
                <span class="rounded-full bg-cyan-50 px-3 py-1 text-xs font-semibold text-cyan-600 dark:bg-cyan-900/40 dark:text-cyan-200">Flow</span>
            </div>
            <p class="mt-4 text-2xl font-semibold text-slate-900 dark:text-slate-100">
                {{ number_format($funds['cash_flow_net'], 2) }}
                <span class="text-xs font-medium text-slate-500 dark:text-slate-400">(Net Cash Flow)</span>
            </p>
            <div class="mt-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                <div class="flex items-center justify-between">
                    <span>Receivable Cash</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($funds['cash_flow_receivable'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Payable Cash</span>
                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ number_format($funds['cash_flow_payable'], 2) }}</span>
                </div>
            </div>
        </div>
        @break
@endswitch
