<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>General Journal</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .meta { color: #475569; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; }
        th { background: #1B4F72; color: #fff; text-align: left; }
        td.num, th.num { text-align: right; }
        tr.total td { font-weight: bold; background: #f1f5f9; }
    </style>
</head>
<body>
    <h1>{{ $company }} — General Journal</h1>
    <div class="meta">Period: {{ $period }}</div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Voucher</th>
                <th>Account</th>
                <th>Description</th>
                <th class="num">Debit</th>
                <th class="num">Credit</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ optional($row->date)->format('d/m/Y') }}</td>
                    <td>{{ $row->voucher_no }}</td>
                    <td>{{ $row->account }}</td>
                    <td>{{ $row->description }}</td>
                    <td class="num">{{ number_format($row->debit, 2) }}</td>
                    <td class="num">{{ number_format($row->credit, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No posted lines in this period.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="4">Total</td>
                <td class="num">{{ number_format($debit_total, 2) }}</td>
                <td class="num">{{ number_format($credit_total, 2) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
