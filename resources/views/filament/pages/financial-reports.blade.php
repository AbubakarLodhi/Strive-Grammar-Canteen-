<x-filament-panels::page>
    <style>
        .fi-financial-reports {
            --navy: #1B4F72;
            --gold: #C4A35A;
            color: #0f172a;
        }
        .fi-financial-reports .fr-banner {
            background: linear-gradient(135deg, #1B4F72 0%, #15405C 55%, #12344a 100%);
            color: #fff;
            border-radius: 1rem;
            padding: 1.5rem 1.75rem;
            margin-bottom: 1.25rem;
        }
        .fi-financial-reports .fr-banner h2 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: 0.01em;
        }
        .fi-financial-reports .fr-banner p {
            margin: 0.35rem 0 0;
            color: #e2e8f0;
            font-size: 0.95rem;
        }
            .fi-financial-reports .fr-kpis {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        .fi-financial-reports .fr-kpi {
            background: #fff;
            border: 1px solid #dbe3ea;
            border-radius: 0.85rem;
            padding: 0.9rem 1rem;
        }
        .fi-financial-reports .fr-kpi span {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #334155;
        }
        .fi-financial-reports .fr-kpi strong {
            display: block;
            margin-top: 0.35rem;
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
            font-variant-numeric: tabular-nums;
        }
        .fi-financial-reports .fr-card {
            background: #fff;
            border: 1px solid #c5d0da;
            border-radius: 1rem;
            overflow: hidden;
            margin-bottom: 1.25rem;
        }
        .fi-financial-reports .fr-card header {
            background: #1B4F72;
            color: #fff;
            padding: 0.9rem 1.15rem;
        }
        .fi-financial-reports .fr-card header h3 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
        }
        .fi-financial-reports .fr-card header p {
            margin: 0.2rem 0 0;
            color: #d6e4ef;
            font-size: 0.82rem;
        }
        .fi-financial-reports table.fr-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.92rem;
        }
        .fi-financial-reports table.fr-table th {
            background: #e8eef3;
            color: #0f172a;
            font-weight: 700;
            text-align: left;
            padding: 0.7rem 0.9rem;
            border-bottom: 2px solid #1B4F72;
            white-space: nowrap;
        }
        .fi-financial-reports table.fr-table td {
            padding: 0.62rem 0.9rem;
            border-bottom: 1px solid #e2e8f0;
            color: #0f172a;
            vertical-align: middle;
        }
        .fi-financial-reports table.fr-table tbody tr:nth-child(even) td {
            background: #f8fafc;
        }
        .fi-financial-reports .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
            font-weight: 600;
            width: 8.5rem;
            white-space: nowrap;
        }
        .fi-financial-reports .code {
            width: 5.5rem;
            font-weight: 700;
            color: #1B4F72;
        }
        .fi-financial-reports .section-row td {
            background: #1B4F72 !important;
            color: #fff !important;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-size: 0.75rem;
        }
        .fi-financial-reports .total-row td {
            background: #f1f5f9 !important;
            font-weight: 800;
            border-top: 2px solid #1B4F72;
            border-bottom: none;
            color: #0f172a;
        }
        .fi-financial-reports .net-row td {
            background: #1B4F72 !important;
            color: #fff !important;
            font-weight: 800;
            border-bottom: none;
        }
        .fi-financial-reports .gold-bar {
            height: 4px;
            background: #C4A35A;
        }
        .fi-financial-reports .bs-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        .fi-financial-reports .bs-grid > div + div {
            border-left: 1px solid #c5d0da;
        }
        @media (max-width: 960px) {
            .fi-financial-reports .fr-kpis,
            .fi-financial-reports .bs-grid {
                grid-template-columns: 1fr;
            }
            .fi-financial-reports .bs-grid > div + div {
                border-left: 0;
                border-top: 1px solid #c5d0da;
            }
        }
        .dark .fi-financial-reports,
        .dark .fi-financial-reports .fr-kpi,
        .dark .fi-financial-reports .fr-card,
        .dark .fi-financial-reports table.fr-table td {
            color: #f8fafc;
        }
        .dark .fi-financial-reports .fr-kpi,
        .dark .fi-financial-reports .fr-card {
            background: #0f172a;
            border-color: #334155;
        }
        .dark .fi-financial-reports .fr-kpi span { color: #cbd5e1; }
        .dark .fi-financial-reports .fr-kpi strong { color: #fff; }
        .dark .fi-financial-reports table.fr-table th {
            background: #1e293b;
            color: #fff;
            border-bottom-color: #C4A35A;
        }
        .dark .fi-financial-reports table.fr-table td {
            border-bottom-color: #334155;
        }
        .dark .fi-financial-reports table.fr-table tbody tr:nth-child(even) td {
            background: #1e293b;
        }
        .dark .fi-financial-reports .total-row td {
            background: #1e293b !important;
            color: #fff;
        }
        .dark .fi-financial-reports .code { color: #C4A35A; }
        @media print {
            .fi-financial-reports .fi-fo-component-ctn,
            .fi-header-actions { display: none !important; }
        }
    </style>

    {{ $this->form }}

    @php
        $statements = $this->statements();
        $tb = $statements['trial_balance'];
        $pl = $statements['profit_and_loss'];
        $bs = $statements['balance_sheet'];
        $money = fn (float $value): string => 'PKR '.number_format($value, 2);
        $balanced = abs($tb['debit_total'] - $tb['credit_total']) < 0.01;
    @endphp

    <div class="fi-financial-reports">
        <div class="fr-banner">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-[#C4A35A]">{{ config('branding.name') }}</div>
            <h2>Financial Statements</h2>
            <p>{{ $statements['period_label'] }} · Trial balance &amp; balance sheet as at {{ $statements['as_at'] }} · Amounts in PKR</p>
        </div>

        <div class="fr-kpis">
            <div class="fr-kpi">
                <span>Net {{ $pl['profit'] >= 0 ? 'profit' : 'loss' }} ({{ $statements['amount_label'] }})</span>
                <strong>{{ $money($pl['profit']) }}</strong>
            </div>
            <div class="fr-kpi">
                <span>Total assets</span>
                <strong>{{ $money($bs['asset_total']) }}</strong>
            </div>
            <div class="fr-kpi">
                <span>Trial balance</span>
                <strong>{{ $balanced ? 'Balanced' : 'Out of balance' }}</strong>
            </div>
        </div>

        <article class="fr-card">
            <div class="gold-bar"></div>
            <header>
                <h3>Trial Balance</h3>
                <p>Ledger accounts with debit and credit balances as at {{ $statements['as_at'] }}.</p>
            </header>
            <table class="fr-table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Type</th>
                        <th class="num">Debit</th>
                        <th class="num">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tb['rows'] as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['type'] }}</td>
                            <td class="num">{{ $row['debit'] > 0 ? $money($row['debit']) : '—' }}</td>
                            <td class="num">{{ $row['credit'] > 0 ? $money($row['credit']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center">No ledger accounts found.</td>
                        </tr>
                    @endforelse
                    <tr class="total-row">
                        <td colspan="2">Total</td>
                        <td class="num">{{ $money($tb['debit_total']) }}</td>
                        <td class="num">{{ $money($tb['credit_total']) }}</td>
                    </tr>
                </tbody>
            </table>
        </article>

        <article class="fr-card">
            <div class="gold-bar"></div>
            <header>
                <h3>Profit and Loss Account</h3>
                <p>Income and expenses for {{ $statements['period_label'] }} only.</p>
            </header>
            <table class="fr-table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th class="num">{{ $statements['amount_label'] }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="section-row"><td colspan="2">Income</td></tr>
                    @forelse ($pl['income'] as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td class="num">{{ $money($row['amount']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2">No income accounts.</td></tr>
                    @endforelse
                    <tr class="total-row">
                        <td>Total income</td>
                        <td class="num">{{ $money($pl['income_total']) }}</td>
                    </tr>
                    <tr class="section-row"><td colspan="2">Expenses</td></tr>
                    @forelse ($pl['expenses'] as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td class="num">{{ $money($row['amount']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2">No expense accounts.</td></tr>
                    @endforelse
                    <tr class="total-row">
                        <td>Total expenses</td>
                        <td class="num">{{ $money($pl['expense_total']) }}</td>
                    </tr>
                    <tr class="net-row">
                        <td>{{ $pl['profit'] >= 0 ? 'Net profit' : 'Net loss' }}</td>
                        <td class="num">{{ $money($pl['profit']) }}</td>
                    </tr>
                </tbody>
            </table>
        </article>

        <article class="fr-card">
            <div class="gold-bar"></div>
            <header>
                <h3>Balance Sheet</h3>
                <p>Financial position as at {{ $statements['as_at'] }}. Current-year profit to that date is included in equity.</p>
            </header>
            <div class="bs-grid">
                <div>
                    <table class="fr-table">
                        <thead>
                            <tr>
                                <th>Assets</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($bs['assets'] as $row)
                                <tr>
                                    <td>{{ $row['name'] }}</td>
                                    <td class="num">{{ $money($row['closing']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2">No asset accounts.</td></tr>
                            @endforelse
                            <tr class="net-row">
                                <td>Total assets</td>
                                <td class="num">{{ $money($bs['asset_total']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <table class="fr-table">
                        <thead>
                            <tr>
                                <th>Liabilities and equity</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="section-row"><td colspan="2">Liabilities</td></tr>
                            @forelse ($bs['liabilities'] as $row)
                                <tr>
                                    <td>{{ $row['name'] }}</td>
                                    <td class="num">{{ $money($row['closing']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2">No liability accounts.</td></tr>
                            @endforelse
                            <tr class="total-row">
                                <td>Total liabilities</td>
                                <td class="num">{{ $money($bs['liability_total']) }}</td>
                            </tr>
                            <tr class="section-row"><td colspan="2">Equity</td></tr>
                            @forelse ($bs['equity'] as $row)
                                <tr>
                                    <td>{{ $row['name'] }}</td>
                                    <td class="num">{{ $money($row['closing']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2">No equity accounts.</td></tr>
                            @endforelse
                            <tr>
                                <td>{{ $bs['period_profit'] >= 0 ? $bs['profit_label'] : str_replace('Profit', 'Loss', $bs['profit_label']) }}</td>
                                <td class="num">{{ $money($bs['period_profit']) }}</td>
                            </tr>
                            <tr class="net-row">
                                <td>Total liabilities and equity</td>
                                <td class="num">{{ $money($bs['financing_total']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </article>
    </div>
</x-filament-panels::page>
