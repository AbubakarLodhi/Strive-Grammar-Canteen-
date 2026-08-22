<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Statements {{ $statements['period_label'] }}</title>
    <style>
        @page { margin: 22px 24px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #1B4F72; }
        .sub { margin: 0 0 14px; color: #334155; font-size: 10px; }
        h2 { font-size: 13px; background: #1B4F72; color: #fff; padding: 6px 8px; margin: 16px 0 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th { background: #e8eef3; text-align: left; border: 1px solid #94a3b8; padding: 5px 6px; font-size: 10px; }
        td { border: 1px solid #cbd5e1; padding: 4px 6px; }
        .num { text-align: right; white-space: nowrap; }
        .section td { background: #1B4F72; color: #fff; font-weight: 700; }
        .total td { background: #e2e8f0; font-weight: 700; }
        .net td { background: #1B4F72; color: #fff; font-weight: 700; }
        .kpis td { border: 1px solid #94a3b8; padding: 8px; }
        .kpis strong { display: block; font-size: 12px; margin-top: 2px; }
    </style>
</head>
<body>
    @php
        $money = fn (float $value): string => number_format($value, 2);
        $pl = $statements['profit_and_loss'];
        $bs = $statements['balance_sheet'];
        $profitLabel = $bs['period_profit'] >= 0
            ? ($bs['profit_label'] ?? 'Profit for the year')
            : str_replace('Profit', 'Loss', $bs['profit_label'] ?? 'Profit for the year');
    @endphp
    <h1>{{ $company }} — Financial Statements</h1>
    <p class="sub">{{ $statements['period_label'] }} · as at {{ $statements['as_at'] }} · Amounts in PKR</p>

    <table class="kpis">
        <tr>
            <td>Net {{ $pl['profit'] >= 0 ? 'profit' : 'loss' }} ({{ $statements['amount_label'] }})<br><strong>{{ $money($pl['profit']) }}</strong></td>
            <td>Total assets<br><strong>{{ $money($bs['asset_total']) }}</strong></td>
        </tr>
    </table>

    <h2>Trial Balance</h2>
    <table>
        <thead>
            <tr>
                <th>Account</th>
                <th>Type</th>
                <th class="num">Debit</th>
                <th class="num">Credit</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statements['trial_balance']['rows'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['type'] }}</td>
                    <td class="num">{{ $row['debit'] > 0 ? $money($row['debit']) : '' }}</td>
                    <td class="num">{{ $row['credit'] > 0 ? $money($row['credit']) : '' }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="2">Total</td>
                <td class="num">{{ $money($statements['trial_balance']['debit_total']) }}</td>
                <td class="num">{{ $money($statements['trial_balance']['credit_total']) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Profit and Loss Account</h2>
    <table>
        <thead>
            <tr>
                <th>Account</th>
                <th class="num">{{ $statements['amount_label'] }}</th>
            </tr>
        </thead>
        <tbody>
            <tr class="section"><td colspan="2">Income</td></tr>
            @foreach ($pl['income'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="num">{{ $money($row['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total income</td>
                <td class="num">{{ $money($pl['income_total']) }}</td>
            </tr>
            <tr class="section"><td colspan="2">Expenses</td></tr>
            @foreach ($pl['expenses'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="num">{{ $money($row['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total expenses</td>
                <td class="num">{{ $money($pl['expense_total']) }}</td>
            </tr>
            <tr class="net">
                <td>{{ $pl['profit'] >= 0 ? 'Net profit' : 'Net loss' }}</td>
                <td class="num">{{ $money($pl['profit']) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Balance Sheet</h2>
    <table>
        <thead>
            <tr>
                <th>Account</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr class="section"><td colspan="2">Assets</td></tr>
            @foreach ($bs['assets'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="num">{{ $money($row['closing']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total assets</td>
                <td class="num">{{ $money($bs['asset_total']) }}</td>
            </tr>
            <tr class="section"><td colspan="2">Liabilities</td></tr>
            @foreach ($bs['liabilities'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="num">{{ $money($row['closing']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total liabilities</td>
                <td class="num">{{ $money($bs['liability_total']) }}</td>
            </tr>
            <tr class="section"><td colspan="2">Equity</td></tr>
            @foreach ($bs['equity'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="num">{{ $money($row['closing']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td>{{ $profitLabel }}</td>
                <td class="num">{{ $money($bs['period_profit']) }}</td>
            </tr>
            <tr class="net">
                <td>Total liabilities and equity</td>
                <td class="num">{{ $money($bs['financing_total']) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
