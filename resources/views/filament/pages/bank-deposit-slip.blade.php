<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $deposit->bankAccount?->name ?? 'Bank' }} Deposit Slip {{ $deposit->deposit_no }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #0f172a; margin: 0; background: #f8fafc; }
        .sheet { max-width: 720px; margin: 24px auto; background: #fff; padding: 32px; border: 1px solid #e2e8f0; }
        .brand { font-size: 13px; letter-spacing: .12em; text-transform: uppercase; color: #1B4F72; }
        h1 { margin: 8px 0 4px; font-size: 26px; }
        .muted { color: #64748b; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; }
        th, td { text-align: left; padding: 10px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        th { color: #64748b; font-weight: 600; width: 40%; }
        .amount { font-size: 28px; font-weight: 700; margin-top: 20px; }
        .ubl-box { margin-top: 28px; padding: 16px; border: 2px dashed #1B4F72; }
        .actions { margin: 16px auto; max-width: 720px; text-align: right; }
        .actions button { background: #1B4F72; color: #fff; border: 0; padding: 8px 14px; cursor: pointer; }
        @media print {
            .actions { display: none; }
            body { background: #fff; }
            .sheet { margin: 0; border: 0; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">Print slip</button>
    </div>
    <div class="sheet">
        <div class="brand">{{ $deposit->merchant?->name ?? config('branding.name') }}</div>
        <h1>{{ $deposit->bankAccount?->name ?? 'Bank' }} Cash Deposit Slip</h1>
        <p class="muted">Take this slip to the bank. After it is stamped, enter the bank slip number on the deposit in Strive, then post.</p>

        <table>
            <tr>
                <th>Deposit slip no</th>
                <td>{{ $deposit->deposit_no }}</td>
            </tr>
            <tr>
                <th>Date</th>
                <td>{{ $deposit->deposit_date?->format('d/m/Y') }}</td>
            </tr>
            <tr>
                <th>Bank</th>
                <td>{{ $deposit->bankAccount?->name }}</td>
            </tr>
            <tr>
                <th>Account number</th>
                <td>{{ $deposit->bankAccount?->account_number ?: '________________' }}</td>
            </tr>
            <tr>
                <th>From</th>
                <td>{{ $deposit->sourceAccount?->name }}</td>
            </tr>
            <tr>
                <th>Bank slip number</th>
                <td>{{ $deposit->reference_no ?: '________________' }}</td>
            </tr>
        </table>

        <div class="amount">PKR {{ number_format((float) $deposit->amount, 2) }}</div>

        <div class="ubl-box">
            <strong>{{ $deposit->bankAccount?->name ?? 'Bank' }} use</strong>
            <p class="muted">Teller stamp / slip number</p>
            <p>________________________________</p>
        </div>
    </div>
</body>
</html>
