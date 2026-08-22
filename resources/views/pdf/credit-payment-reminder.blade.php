@php
    use App\Enums\CreditReminderScheduleType;
    use App\Support\CreditReminderEmailCaption;

    $merchant = $sale->merchant;
    $customer = $sale->customer;
    $settings = $merchant?->settings;
    $themeBlue = $settings?->primary_color ?? '#1B4F72';
    $themeGreen = $settings?->success_color ?? '#0bb783';
    $themeTeal = '#1bc5bd';

    $schedule = isset($variables['schedule_type'])
        ? CreditReminderScheduleType::tryFrom((string) $variables['schedule_type'])
        : null;

    $alert = match ($schedule) {
        CreditReminderScheduleType::BeforeDueDate => [
            'title' => 'Payment due soon',
            'bg' => '#eff6ff',
            'border' => $themeBlue,
            'text' => '#1e3a8a',
        ],
        CreditReminderScheduleType::OnDueDate => [
            'title' => 'Payment due today',
            'bg' => '#fff7ed',
            'border' => '#ea580c',
            'text' => '#9a3412',
        ],
        CreditReminderScheduleType::AfterDueDate => [
            'title' => 'Payment overdue',
            'bg' => '#fef2f2',
            'border' => '#dc2626',
            'text' => '#991b1b',
        ],
        default => [
            'title' => $variables['schedule_label'] ?? 'Payment reminder',
            'bg' => '#f8fafc',
            'border' => $themeBlue,
            'text' => '#334155',
        ],
    };

    $payments = ($sale->payments ?? collect())->sortBy([
        ['payment_date', 'asc'],
        ['created_at', 'asc'],
    ])->values();

    $messageIntro = CreditReminderEmailCaption::forSchedule($schedule, $recipientRole ?? 'customer');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment reminder — {{ $sale->sale_no }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9.5pt;
            color: #111827;
            line-height: 1.35;
            margin: 0;
            padding: 0;
        }
        p { margin: 0 0 6px 0; }
        .header {
            border-bottom: 2px solid {{ $themeBlue }};
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: top; padding: 0; border: none; }
        .logo { max-width: 100px; max-height: 44px; }
        .doc-title {
            font-size: 15pt;
            font-weight: bold;
            color: {{ $themeBlue }};
            margin: 0 0 2px 0;
        }
        .doc-meta { font-size: 8.5pt; color: #4b5563; }
        .alert {
            background: {{ $alert['bg'] }};
            border-left: 3px solid {{ $alert['border'] }};
            padding: 8px 10px;
            margin-bottom: 10px;
        }
        .alert-title {
            font-size: 10.5pt;
            font-weight: bold;
            color: {{ $alert['text'] }};
            margin-bottom: 4px;
        }
        .alert-line { font-size: 9pt; color: {{ $alert['text'] }}; }
        .section-title {
            font-size: 9.5pt;
            font-weight: bold;
            color: {{ $themeBlue }};
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 3px;
            margin: 10px 0 6px 0;
        }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .info-table td {
            padding: 3px 0;
            font-size: 9pt;
            vertical-align: top;
        }
        .info-table .label { color: #6b7280; width: 32%; }
        .info-table .value { font-weight: bold; }
        .two-col { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .two-col td { width: 50%; vertical-align: top; padding: 0 8px 0 0; border: none; }
        .items-table { width: 100%; border-collapse: collapse; margin: 4px 0 8px 0; font-size: 8.5pt; }
        .items-table th {
            background: #f3f4f6;
            color: #374151;
            font-weight: bold;
            padding: 5px 4px;
            border: 1px solid #d1d5db;
            text-align: left;
        }
        .items-table td {
            padding: 4px;
            border: 1px solid #e5e7eb;
            vertical-align: top;
        }
        .col-num { width: 4%; text-align: center; }
        .col-product { width: 28%; }
        .col-money { width: 12%; text-align: right; }
        .col-qty { width: 8%; text-align: center; }
        .col-pct { width: 8%; text-align: right; }
        .muted { color: #6b7280; font-size: 8pt; }
        .totals-wrap { width: 100%; margin-top: 4px; }
        .totals-wrap td { border: none; padding: 0; }
        .totals {
            width: 48%;
            margin-left: auto;
            border-collapse: collapse;
            font-size: 9pt;
        }
        .totals td { padding: 4px 6px; border: none; }
        .totals .label { color: #4b5563; }
        .totals .value { text-align: right; }
        .grand-total {
            background-color: {{ $themeGreen }};
            color: #ffffff;
            font-weight: bold;
            font-size: 10pt;
        }
        .grand-total td { padding: 7px 8px; }
        .closing { font-size: 9pt; color: #4b5563; margin-top: 8px; }
        .footer {
            margin-top: 10px;
            padding-top: 6px;
            border-top: 1px solid #e5e7eb;
            font-size: 7.5pt;
            color: #9ca3af;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 68%;">
                    <p class="doc-title">Payment reminder</p>
                    <p class="doc-meta"><strong>{{ $merchant?->name }}</strong></p>
                    <p class="doc-meta">Invoice {{ $sale->sale_no }} · Due {{ $variables['due_date'] ?? '—' }}</p>
                </td>
                <td style="width: 32%; text-align: right;">
                    @if(!empty($merchantLogoPath))
                        <img src="{{ $merchantLogoPath }}" alt="" class="logo">
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="alert">
        <div class="alert-title">{{ $alert['title'] }}</div>
        <div class="alert-line">Dear {{ $variables['customer_name'] ?? 'Customer' }}, {{ $messageIntro }}</div>
        <div class="alert-line" style="margin-top:4px;">
            Outstanding balance: <strong>Rs {{ $variables['due_amount'] ?? '0.00' }}</strong>
            · Due date: <strong>{{ $variables['due_date'] ?? '—' }}</strong>
        </div>
    </div>

    <table class="two-col">
        <tr>
            <td>
                <div class="section-title">Customer</div>
                <table class="info-table">
                    <tr><td class="label">Name</td><td class="value">{{ $variables['customer_name'] ?? '—' }}</td></tr>
                    <tr><td class="label">Email</td><td>{{ $variables['customer_email'] ?? '—' }}</td></tr>
                    <tr><td class="label">Phone</td><td>{{ $variables['customer_phone_no'] ?? '—' }}</td></tr>
                </table>
            </td>
            <td>
                <div class="section-title">Invoice</div>
                <table class="info-table">
                    <tr><td class="label">Number</td><td class="value">{{ $sale->sale_no }}</td></tr>
                    <tr><td class="label">Sale date</td><td>{{ $variables['sale_date'] ?? '—' }}</td></tr>
                    <tr><td class="label">Payment type</td><td>{{ $variables['payment_type_label'] ?? 'Credit' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section-title">Line items</div>
    @include('pdf.partials.sale-line-items', ['sale' => $sale])

    <table class="totals-wrap">
        <tr>
            <td>
                <table class="totals">
                    <tr>
                        <td class="label">Subtotal</td>
                        <td class="value">Rs {{ $variables['subtotal'] ?? '0.00' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Amount paid</td>
                        <td class="value">Rs {{ $variables['paid_amount'] ?? '0.00' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Amount due</td>
                        <td class="value" style="color:#b45309;font-weight:bold;">Rs {{ $variables['due_amount'] ?? '0.00' }}</td>
                    </tr>
                    <tr class="grand-total">
                        <td>Grand total</td>
                        <td class="value">Rs {{ $variables['total_amount'] ?? '0.00' }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if($payments->isNotEmpty())
        <div class="section-title">Payment history</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($payments as $payment)
                    <tr>
                        <td>{{ $payment->payment_date?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ ucfirst((string) ($payment->entry_type ?? 'payment')) }}</td>
                        <td style="text-align:right;">Rs {{ number_format((float) ($payment->amount ?? 0), 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="closing">
        Please arrange payment by the due date above. If you have already paid, please disregard this message or contact us with your payment reference.
    </p>
    <p class="closing">
        Thank you, <strong>{{ $merchant?->name }}</strong>
        @if($merchant?->email) · {{ $merchant->email }} @endif
        @if($merchant?->phone) · {{ $merchant->phone }} @endif
    </p>

    <div class="footer">
        Generated {{ $variables['current_date'] ?? now()->format('d/m/Y') }} · {{ $merchant?->name }}
    </div>
</body>
</html>
