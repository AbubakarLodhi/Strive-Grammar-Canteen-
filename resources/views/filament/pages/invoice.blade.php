@php
    $paperFormat = in_array(request()->query('paper'), ['a4', 'receipt'], true)
        ? request()->query('paper')
        : 'receipt';
@endphp
<!DOCTYPE html>
<html lang="en" class="paper-{{ $paperFormat }}">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php
        $isSale = $type === 'sale';

        $invoiceNo   = $isSale ? $record->sale_no       : $record->purchase_no;
        $invoiceDate = $isSale ? $record->sale_date     : $record->purchase_date;
        $paidAmount = (float) ($record->paid_amount ?? 0);
        $remainingAmount = (float) ($record->due_amount ?? 0);

        // Customer for Sale | Vendor for Purchase
        $party = $isSale ? $record->customer : $record->vendor;
        $formatPercent = function (float $value): string {
            $formatted = number_format($value, 2, '.', '');
            $formatted = rtrim(rtrim($formatted, '0'), '.');

            return ($formatted === '' ? '0' : $formatted) . '%';
        };
        $totalDiscount = 0;
        $totalTax = 0;
        $totalDiscountPercent = 0;
        $totalTaxPercent = 0;

        foreach ($record->items as $item) {
            $lineTotal = (float) ($item->line_total ?? 0);
            $discountRate = (float) ($item->discount ?? 0);
            $taxRate = (float) ($item->tax ?? 0);

            $discountAmount = $lineTotal * ($discountRate / 100);
            $taxableAmount = $lineTotal - $discountAmount;
            $taxAmount = $taxableAmount * ($taxRate / 100);

            $totalDiscount += $discountAmount;
            $totalTax += $taxAmount;
            $totalDiscountPercent += $discountRate;
            $totalTaxPercent += $taxRate;
        }

        $headerGroupOptions = $headerGroupOptions ?? ['__default' => 'Default (Current Header)'];
        $footerGroupOptions = $footerGroupOptions ?? ['__default' => 'Default (Current Footer)'];
        $selectedHeaderGroupId = (string) ($selectedHeaderGroupId ?? array_key_first($headerGroupOptions));
        $selectedFooterGroupId = (string) ($selectedFooterGroupId ?? array_key_first($footerGroupOptions));
        $dynamicGroups = $dynamicGroups ?? ['header' => [], 'footer' => []];
        $headerGroups = $dynamicGroups['header'] ?? [];
        $footerGroups = $dynamicGroups['footer'] ?? [];
        $showDefaultHeader = $selectedHeaderGroupId === '__default';
        $showDefaultFooter = $selectedFooterGroupId === '__default';

        $configuredHeaderLogo = null;
        $contactNumber = '+923452202223';
        foreach ($headerGroups as $group) {
            foreach (($group['fields'] ?? []) as $field) {
                if (($field['value_type'] ?? null) === 'business_logo' && filled($field['value'] ?? null)) {
                    $configuredHeaderLogo = (string) $field['value'];
                    break 2;
                }
            }
        }
    @endphp

    @php
        $a4PrintUrl = route('invoices.show', array_filter([
            'type' => $type,
            'id' => $record->id,
            'paper' => 'a4',
            'header' => request()->query('header'),
            'footer' => request()->query('footer'),
            'combo' => request()->query('combo'),
        ]));
    @endphp

    <title>Invoice {{ $invoiceNo }}</title>

    <style>
        :root {
            --ink: #1f2937;
            --muted: #6b7280;
            --line: #e5e7eb;
            --accent: #111827;
            --brand: #1B4F72;
            --brand-light: #2E6A96;
            --gold: #C4A35A;
            --bg: #f3f4f6;
            --invoice-frame-width: min(900px, calc(100vw - 24px));
            --invoice-arrow-gap: 52px;
            --slip-width: 72mm;
            --slip-side-padding: 3mm;
        }

        body {
            font-family: "Helvetica Neue", Arial, "Noto Sans", sans-serif;
            background: var(--bg);
            margin: 0;
            padding: 30px 0;
            color: var(--ink);
        }

        .invoice {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            padding: 48px 54px 60px;
            border-radius: 24px;
            box-shadow: 0 10px 40px rgba(15, 23, 42, 0.12);
            box-sizing: border-box;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .header {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            align-items: start;
        }

        .brand {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 45%;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.5;
        }

        .brand img {
            height: 44px;
            width: auto;
        }

        .invoice-title {
            text-align: right;
            max-width: 45%;
        }

        .invoice-title h1 {
            margin: 0 0 8px;
            font-size: 26px;
            letter-spacing: 2px;
            font-weight: 600;
            color: var(--accent);
        }

        .invoice-title .subtitle {
            font-size: 12px;
            color: var(--muted);
        }


        .party-row {
            margin-top: 26px;
            display: flex;
            justify-content: space-between;
            gap: 24px;
            font-size: 12.5px;
            color: var(--muted);
        }

        .party-row strong {
            color: var(--accent);
        }

        .invoice-meta {
            margin-top: 18px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 28px;
            font-size: 12.5px;
            color: var(--muted);
        }

        .invoice-meta div {
            display: grid;
            grid-template-columns: 96px minmax(0, 1fr);
            align-items: baseline;
            gap: 10px;
        }

        .invoice-meta span.label {
            color: var(--muted);
        }

        .divider {
            margin: 26px 0 14px;
            border-top: 1px solid var(--line);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        thead th {
            text-align: left;
            font-size: 12px;
            color: #fff;
            background: #111827;
            padding: 10px 8px;
            font-weight: 600;
        }

        tbody td {
            padding: 12px 8px;
            border-bottom: 1px solid var(--line);
            font-size: 13px;
            vertical-align: top;
        }

        .item-meta {
            font-size: 11px;
            color: var(--muted);
            margin-top: 4px;
        }

        .summary {
            width: 320px;
            margin-left: auto;
            margin-top: auto;
            font-size: 12.5px;
            color: var(--muted);
            background: #fff;
            padding: 0;
            border-radius: 0;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .summary div {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
        }

        .summary .grand {
            margin-top: 6px;
            background: #111827;
            padding: 10px 12px;
            border-radius: 6px;
            font-weight: 600;
            color: #fff;
        }


        .notes {
            margin-top: 32px;
            font-size: 12px;
            color: var(--muted);
        }

        .payment-history {
            margin-top: 18px;
            font-size: 12px;
            color: var(--muted);
        }

        .payment-history table {
            margin-top: 8px;
        }

        .payment-history th,
        .payment-history td {
            text-align: left;
            padding: 8px 6px;
            border-bottom: 1px solid var(--line);
        }

        .contact-us {
            margin-top: 18px;
            font-size: 12px;
            color: var(--muted);
        }

        .contact-us strong {
            color: var(--accent);
        }

        .footer-gap {
            flex: 1 1 auto;
        }

        .a4-only {
            display: block;
        }

        .thermal-slip {
            display: none;
            box-sizing: border-box;
            width: var(--slip-width);
            max-width: 100%;
            margin: 0 auto;
            padding: 0 var(--slip-side-padding);
            font-size: 12px;
            line-height: 1.4;
            color: #000;
        }

        .thermal-slip * {
            box-sizing: border-box;
        }

        body.paper-receipt .invoice {
            width: var(--slip-width);
            max-width: var(--slip-width);
            margin: 0 auto;
            padding: 12px 0 20px;
            border-radius: 8px;
            min-height: auto;
            box-shadow: 0 8px 28px rgba(15, 23, 42, 0.14);
        }

        body.paper-receipt .a4-only {
            display: none;
        }

        body.paper-receipt .thermal-slip {
            display: block;
        }

        .slip-brand {
            text-align: center;
            padding-bottom: 10px;
        }

        .slip-brand img {
            height: 42px;
            width: auto;
            margin-bottom: 6px;
        }

        .slip-brand-name {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--brand);
            line-height: 1.3;
        }

        .slip-brand-details {
            margin-top: 4px;
            font-size: 10px;
            color: var(--muted);
            line-height: 1.5;
        }

        .slip-divider {
            height: 1px;
            background: #000;
            margin: 8px 0;
            border: 0;
        }

        .slip-heading {
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #000;
        }

        .slip-counter {
            display: none;
            text-align: center;
            margin: 6px 0 2px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .slip-counter.is-ready {
            display: block;
        }

        .slip-counter span {
            font-size: 11px;
            font-weight: 700;
            margin-left: 4px;
        }

        .slip-meta-grid {
            display: grid;
            gap: 4px;
            font-size: 10.5px;
        }

        .slip-meta-row {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            align-items: flex-start;
        }

        .slip-meta-row span:first-child {
            color: var(--muted);
            flex: 0 0 auto;
            max-width: 42%;
        }

        .slip-meta-row span:last-child {
            flex: 1 1 auto;
            text-align: right;
            font-weight: 600;
            color: #000;
            overflow-wrap: anywhere;
            padding-right: 1px;
        }

        .slip-section-title {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--brand);
            margin-bottom: 6px;
        }

        .slip-items-header {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
            padding-bottom: 4px;
            border-bottom: 1px solid #000;
        }

        .slip-item {
            padding: 6px 0 4px;
            border-bottom: 1px solid #e5e7eb;
        }

        .slip-item:last-child {
            border-bottom: 0;
        }

        .slip-item-name {
            font-weight: 700;
            font-size: 11px;
            line-height: 1.35;
        }

        .slip-item-variant {
            font-size: 9.5px;
            color: var(--muted);
            margin-top: 1px;
        }

        .slip-item-line {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            margin-top: 3px;
            font-size: 10px;
            align-items: flex-start;
        }

        .slip-item-qty {
            color: var(--muted);
            flex: 1 1 auto;
            min-width: 0;
            overflow-wrap: anywhere;
            padding-right: 4px;
        }

        .slip-item-amount {
            font-weight: 700;
            white-space: nowrap;
            flex: 0 0 auto;
            padding-right: 1px;
        }

        .slip-total-row {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            padding: 3px 0;
            font-size: 10.5px;
            align-items: flex-start;
        }

        .slip-total-row span:last-child {
            font-weight: 600;
            white-space: nowrap;
            flex: 0 0 auto;
            padding-right: 1px;
        }

        .slip-grand-total {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            margin-top: 4px;
            padding-top: 6px;
            padding-right: 1px;
            border-top: 1px solid #000;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            align-items: center;
        }

        .slip-grand-total span:last-child {
            white-space: nowrap;
            flex: 0 0 auto;
        }

        .slip-totals {
            margin-top: 8px;
        }

        .slip-total-row span:first-child {
            color: var(--muted);
        }

        .slip-items-header span:last-child {
            text-align: right;
            padding-right: 1px;
        }

        .slip-payments {
            margin-top: 10px;
        }

        .slip-payment-row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            padding: 3px 0;
            font-size: 10px;
        }

        .slip-payment-row span:last-child {
            font-weight: 700;
        }

        .slip-notes {
            margin-top: 10px;
            padding: 8px;
            border: 1px dashed #d1d5db;
            font-size: 10px;
            color: var(--muted);
        }

        .slip-notes strong {
            display: block;
            font-size: 9px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #000;
            margin-bottom: 4px;
        }

        .slip-footer {
            margin-top: 12px;
            text-align: center;
            font-size: 10px;
            color: var(--muted);
            line-height: 1.55;
        }

        .slip-footer strong {
            display: block;
            font-size: 11px;
            color: #000;
            margin-bottom: 4px;
        }

        .slip-footer-thanks {
            margin-top: 8px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #000;
        }

        .actions {
            position: fixed;
            top: 24px;
            left: 24px;
            right: 24px;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            z-index: 10;
            align-items: flex-start;
            pointer-events: none;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
            pointer-events: auto;
        }

        .btn {
            border: none;
            background: #111827;
            color: #fff;
            padding: 6px 12px;
            border-radius: 999px;
            cursor: pointer;
            font-size: 12px;
            line-height: 18px;
        }

        .btn.secondary {
            background: #e5e7eb;
            color: #111827;
        }

        .combo-controls {
            display: flex;
            flex-direction: column;
            gap: 8px;
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 10px 12px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
            pointer-events: auto;
            min-width: 320px;
        }

        .combo-label {
            font-size: 12px;
            color: #374151;
            font-weight: 600;
            line-height: 1.2;
        }

        .combo-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .combo-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }

        .combo-select {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #111827;
            font-size: 12px;
            border-radius: 8px;
            padding: 6px 8px;
            width: 100%;
        }

        .side-arrow {
            position: fixed;
            top: 50%;
            transform: translateY(-50%);
            border: 1px solid #d1d5db;
            background: #fff;
            color: #111827;
            width: 40px;
            height: 40px;
            border-radius: 999px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            line-height: 1;
            padding: 0;
            z-index: 12;
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.12);
        }

        .side-arrow.left {
            left: max(12px, calc(50% - (var(--invoice-frame-width) / 2) - var(--invoice-arrow-gap)));
        }

        .side-arrow.right {
            right: max(12px, calc(50% - (var(--invoice-frame-width) / 2) - var(--invoice-arrow-gap)));
        }

        .side-arrow:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .invoice-nav-arrow {
            text-decoration: none;
        }

        body.paper-receipt {
            padding-top: 100px;
        }

        @media (max-width: 900px) {
            .actions {
                position: static;
                margin: 12px;
                justify-content: space-between;
                flex-wrap: wrap;
            }

            .combo-controls {
                width: 100%;
                min-width: 0;
            }

            .combo-fields {
                grid-template-columns: 1fr;
            }

            .side-arrow {
                display: none;
            }
        }

        @page receipt {
            size: 72mm auto;
            margin: 0;
        }

        @page a4 {
            size: A4 portrait;
            margin: 10mm;
        }

        @media print {
            html.paper-receipt {
                page: receipt;
                width: 72mm;
                max-width: 72mm;
            }

            html.paper-a4 {
                page: a4;
            }

            body.paper-receipt {
                width: 72mm !important;
                max-width: 72mm !important;
                min-width: 72mm !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            body.paper-receipt > div,
            body.paper-receipt section {
                width: 72mm;
                max-width: 72mm;
                margin: 0;
                padding: 0;
            }

            html {
                font-size: 11pt;
            }

            body {
                background: #fff;
                color: #000;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            body.paper-a4 {
                width: auto;
                max-width: none;
                min-width: 0;
                margin: 0 auto;
                padding: 0;
            }

            body.paper-receipt .thermal-slip {
                display: block !important;
                width: 72mm !important;
                max-width: 72mm !important;
                margin: 0 !important;
                padding: 2mm 4mm 2mm 2mm;
                font-size: 11pt;
                break-inside: avoid;
                page-break-inside: avoid;
            }

            body.paper-receipt .slip-item-amount,
            body.paper-receipt .slip-total-row span:last-child,
            body.paper-receipt .slip-grand-total span:last-child,
            body.paper-receipt .slip-meta-row span:last-child,
            body.paper-receipt .slip-items-header span:last-child {
                padding-right: 2mm;
            }

            body.paper-receipt .slip-footer,
            body.paper-receipt .slip-totals,
            body.paper-receipt .slip-brand {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .invoice {
                box-shadow: none;
                border-radius: 0;
                width: 72mm !important;
                max-width: 72mm !important;
                margin: 0 !important;
                padding: 0 !important;
                min-height: auto;
                height: auto;
                display: block;
            }

            body.paper-receipt .a4-only {
                display: none !important;
            }

            body.paper-a4 .thermal-slip {
                display: none !important;
            }

            body.paper-a4 .a4-only {
                display: block !important;
            }

            .slip-counter.is-ready {
                display: block !important;
            }

            .actions {
                display: none;
            }

            .side-arrow {
                display: none !important;
            }

            body.paper-a4 .header {
                flex-direction: row;
                align-items: start;
                text-align: left;
            }

            body.paper-a4 .brand {
                max-width: 48%;
                align-items: start;
                font-size: 11pt;
            }

            body.paper-a4 .brand img {
                height: 52px;
            }

            body.paper-a4 .invoice-title {
                text-align: right;
                max-width: 48%;
            }

            body.paper-a4 .invoice-title h1 {
                font-size: 24pt;
            }

            body.paper-a4 .party-row {
                flex-direction: row;
                font-size: 11pt;
            }

            body.paper-a4 .invoice-meta {
                grid-template-columns: 1fr 1fr;
                font-size: 11pt;
            }

            body.paper-a4 thead th {
                font-size: 10.5pt;
                padding: 8px 6px;
            }

            body.paper-a4 tbody td {
                font-size: 11pt;
                padding: 10px 6px;
            }

            body.paper-a4 .summary {
                width: 42%;
            }
        }
    </style>
</head>
<body class="paper-{{ $paperFormat }}">

<div class="actions">
    <form
        id="invoice-template-form"
        class="combo-controls"
        method="GET"
        action="{{ route('invoices.show', ['type' => $type, 'id' => $record->id]) }}"
    >
        <input type="hidden" name="paper" value="{{ $paperFormat }}">
        <span class="combo-label">Invoice Template</span>

        <div class="combo-fields">
            <div class="combo-field">
                <label class="combo-label" for="invoice-header-select">Header</label>
                <select id="invoice-header-select" name="header" class="combo-select">
                    @foreach($headerGroupOptions as $optionId => $optionLabel)
                        <option value="{{ $optionId }}" @selected((string) $optionId === $selectedHeaderGroupId)>
                            {{ $optionLabel }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="combo-field">
                <label class="combo-label" for="invoice-footer-select">Footer</label>
                <select id="invoice-footer-select" name="footer" class="combo-select">
                    @foreach($footerGroupOptions as $optionId => $optionLabel)
                        <option value="{{ $optionId }}" @selected((string) $optionId === $selectedFooterGroupId)>
                            {{ $optionLabel }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </form>

    <div class="action-buttons">
        <button class="btn" type="button" id="print-slip-button">Print slip (72mm)</button>
        @if($paperFormat === 'a4')
            <a class="btn secondary" href="{{ route('invoices.show', array_filter(['type' => $type, 'id' => $record->id, 'paper' => 'receipt', 'header' => request()->query('header'), 'footer' => request()->query('footer'), 'combo' => request()->query('combo')])) }}" style="text-decoration: none; display: inline-block;">Slip layout</a>
        @else
            <a class="btn secondary" href="{{ $a4PrintUrl }}" style="text-decoration: none; display: inline-block;">A4 layout</a>
        @endif
        <button
            class="btn secondary"
            type="button"
            aria-label="Close"
            onclick="window.location.href = @js($closeUrl ?? url('/'))"
        >
            Close
        </button>
    </div>
</div>

<button
    type="button"
    id="invoice-prev-arrow"
    class="side-arrow left invoice-nav-arrow"
    aria-label="Previous Invoice"
    data-href="{{ $previousInvoiceUrl ?? '' }}"
    @if(empty($previousInvoiceUrl)) disabled @endif
>&lt;</button>

<button
    type="button"
    id="invoice-next-arrow"
    class="side-arrow right invoice-nav-arrow"
    aria-label="Next Invoice"
    data-href="{{ $nextInvoiceUrl ?? '' }}"
    @if(empty($nextInvoiceUrl)) disabled @endif
>&gt;</button>

<div>
    <section>
        <div class="invoice">
                <div class="thermal-slip">
                    <div class="slip-brand">
                        @if($configuredHeaderLogo)
                            <img src="{{ asset('storage/'.$configuredHeaderLogo) }}" alt="{{ $record->merchant?->name }}">
                        @elseif($record->merchant?->logo)
                            <img src="{{ asset('storage/'.$record->merchant->logo->photo_url) }}" alt="{{ $record->merchant?->name }}">
                        @endif

                        <div class="slip-brand-name">{{ $record->merchant?->name }}</div>

                        <div class="slip-brand-details">
                            @if($showDefaultHeader)
                                @if($record->merchant?->address)
                                    {{ $record->merchant->address }}<br>
                                @endif
                                @if($record->merchant?->city || $record->merchant?->country)
                                    {{ trim(($record->merchant?->city ?? '').' '.($record->merchant?->country ?? '')) }}
                                @endif
                            @endif

                            @foreach($headerGroups as $group)
                                <strong>{{ $group['group_name'] }}</strong><br>
                                @foreach($group['fields'] as $field)
                                    @continue(($field['value_type'] ?? null) === 'business_logo')
                                    {{ $field['label'] }}: {{ $field['value'] }}<br>
                                @endforeach
                            @endforeach
                        </div>
                    </div>

                    <div class="slip-heading">{{ $isSale ? 'Sales Receipt' : 'Purchase Receipt' }}</div>

                    <div class="slip-counter" id="slip-counter" hidden>
                        Slip No.<span id="slip-print-number"></span>
                    </div>

                    <div class="slip-divider"></div>

                    <div class="slip-meta-grid">
                        <div class="slip-meta-row">
                            <span>Date</span>
                            <span>{{ $record->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                        </div>
                        <div class="slip-meta-row">
                            <span>Invoice #</span>
                            <span>{{ $invoiceNo }}</span>
                        </div>
                        @if($isSale && filled($record->payment_type ?? null))
                            <div class="slip-meta-row">
                                <span>Payment</span>
                                <span>{{ ucfirst((string) $record->payment_type) }}</span>
                            </div>
                        @endif
                        <div class="slip-meta-row">
                            <span>{{ $isSale ? 'Customer' : 'Vendor' }}</span>
                            <span>{{ $party?->name ?? '—' }}</span>
                        </div>
                        <div class="slip-meta-row">
                            <span>Staff</span>
                            <span>{{ $record->createdBy?->name ?: ($record->merchant?->name ?: '—') }}</span>
                        </div>
                    </div>

                    <div class="slip-divider"></div>

                    <div class="slip-section-title">Items</div>
                    <div class="slip-items-header">
                        <span>Description</span>
                        <span>Amount</span>
                    </div>

                    @foreach($record->items as $i => $item)
                        @php
                            $lineTotal = (float) ($item->line_total ?? 0);
                            $discountRate = (float) ($item->discount ?? 0);
                            $taxRate = (float) ($item->tax ?? 0);
                            $discountAmount = $lineTotal * ($discountRate / 100);
                            $taxableAmount = $lineTotal - $discountAmount;
                            $taxAmount = $taxableAmount * ($taxRate / 100);
                            $lineGrandTotal = $taxableAmount + $taxAmount;
                        @endphp
                        <div class="slip-item">
                            <div class="slip-item-name">{{ $i + 1 }}. {{ $item->product?->name }}</div>
                            @if($item->variants->first())
                                <div class="slip-item-variant">{{ $item->variants->first()->variant?->name }}</div>
                            @endif
                            <div class="slip-item-line">
                                <span class="slip-item-qty">{{ $item->quantity }} × Rs{{ number_format($item->unit_price, 2) }}</span>
                                <span class="slip-item-amount">Rs{{ number_format($lineGrandTotal, 2) }}</span>
                            </div>
                            @if($discountRate > 0 || $taxRate > 0)
                                <div class="slip-item-line">
                                    <span class="slip-item-qty">
                                        @if($discountRate > 0) Disc {{ $formatPercent($discountRate) }} @endif
                                        @if($discountRate > 0 && $taxRate > 0) · @endif
                                        @if($taxRate > 0) Tax {{ $formatPercent($taxRate) }} @endif
                                    </span>
                                </div>
                            @endif
                        </div>
                    @endforeach

                    <div class="slip-totals">
                        <div class="slip-total-row">
                            <span>Subtotal</span>
                            <span>Rs{{ number_format($record->subtotal, 2) }}</span>
                        </div>
                        @if($totalDiscount > 0)
                            <div class="slip-total-row">
                                <span>Discount</span>
                                <span>- Rs{{ number_format($totalDiscount, 2) }}</span>
                            </div>
                        @endif
                        @if($totalTax > 0)
                            <div class="slip-total-row">
                                <span>Tax</span>
                                <span>Rs{{ number_format($totalTax, 2) }}</span>
                            </div>
                        @endif
                        <div class="slip-total-row">
                            <span>Paid</span>
                            <span>Rs{{ number_format($paidAmount, 2) }}</span>
                        </div>
                        @if($remainingAmount > 0)
                            <div class="slip-total-row">
                                <span>Balance Due</span>
                                <span>Rs{{ number_format($remainingAmount, 2) }}</span>
                            </div>
                        @endif
                        <div class="slip-grand-total">
                            <span>Total</span>
                            <span>Rs{{ number_format($record->total_amount, 2) }}</span>
                        </div>
                    </div>

                    @if($record->notes)
                        <div class="slip-notes">
                            <strong>Notes</strong>
                            {{ $record->notes }}
                        </div>
                    @endif

                    <div class="slip-divider"></div>

                    <div class="slip-footer">
                        @if($showDefaultFooter)
                            <strong>Contact</strong>
                            <strong>{{ $contactNumber }}</strong>
                        @endif

                        @foreach($footerGroups as $group)
                            <strong>{{ $group['group_name'] }}</strong><br>
                            @foreach($group['fields'] as $field)
                                @continue(($field['value_type'] ?? null) === 'business_logo')
                                {{ $field['label'] }}: {{ $field['value'] }}<br>
                            @endforeach
                        @endforeach

                        <div class="slip-footer-thanks">Thank you for your time</div>
                    </div>
                </div>

                <div class="a4-only">
                <div class="header">
                    <div class="brand">
                        @if($configuredHeaderLogo)
                            <img src="{{ asset('storage/'.$configuredHeaderLogo) }}">
                        @elseif($record->merchant?->logo)
                            <img src="{{ asset('storage/'.$record->merchant->logo->photo_url) }}">
                        @else
                            <strong>{{ $record->merchant?->name }}</strong>
                        @endif

                        @if($showDefaultHeader)
                            <div>
                                {{ $record->merchant?->name }}<br>
                                @if($record->merchant?->address)
                                    {{ $record->merchant->address }}<br>
                                @endif
                                @if($record->merchant?->city || $record->merchant?->country)
                                    {{ $record->merchant->city }} {{ $record->merchant->country }}<br>
                                @endif
                                <strong>{{ $contactNumber }}</strong><br>
                                @if($record->merchant?->vat_number)
                                    VAT: {{ $record->merchant->vat_number }}
                                @endif
                            </div>
                        @endif

                        @foreach($headerGroups as $group)
                            <div>
                                <strong>{{ $group['group_name'] }}</strong><br>
                                @foreach($group['fields'] as $field)
                                    @continue(($field['value_type'] ?? null) === 'business_logo')
                                    {{ $field['label'] }}: {{ $field['value'] }}<br>
                                @endforeach
                            </div>
                        @endforeach
                    </div>

                    <div class="invoice-title">
                        <h1>INVOICE</h1>
                        <div class="subtitle">Invoice# {{ $invoiceNo }}</div>
                    </div>
                </div>

                <div class="party-row">
                    <div>
                        <strong>{{ $isSale ? 'Bill To' : 'Bill From' }}</strong><br>
                        {{ $party?->name }}<br>
                        @if($party?->email)
                            {{ $party->email }}<br>
                        @endif
                        {{ $party?->address ?? '—' }}
                    </div>
                    <div>
                        <div class="invoice-meta">
                            <div><span class="label">Invoice Date</span><span>{{ $invoiceDate?->format('d/m/Y') }}</span></div>
                            <div><span class="label">Invoice #</span><span>{{ $invoiceNo }}</span></div>
                            <div><span class="label">Due Date</span><span>{{ $invoiceDate?->format('d/m/Y') }}</span></div>
                            <div aria-hidden="true"></div>
                            <div><span class="label">Created By</span><span>{{ $record->createdBy?->name ?: ($record->merchant?->name ?: '—') }}</span></div>
                        </div>
                    </div>
                </div>

                <div class="divider"></div>

                <table class="invoice-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Item & Description</th>
                        <th>Price</th>
                        <th>Qty</th>
                        <th>Subtotal</th>
                        <th>Discount (%)</th>
                        <th>Tax (%)</th>
                        <th>Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($record->items as $i => $item)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>
                                {{ $item->product?->name }}
                                @if($item->variants->first())
                                    <div class="item-meta">
                                        {{ $item->variants->first()->variant?->name }}
                                    </div>
                                @endif
                            </td>
                            <td>Rs{{ number_format($item->unit_price, 2) }}</td>
                            <td>{{ $item->quantity }}</td>
                            <td>Rs{{ number_format($item->line_total, 2) }}</td>
                            <td>{{ $formatPercent((float) ($item->discount ?? 0)) }}</td>
                            <td>{{ $formatPercent((float) ($item->tax ?? 0)) }}</td>
                            @php
                                $lineTotal = (float) ($item->line_total ?? 0);
                                $discountRate = (float) ($item->discount ?? 0);
                                $taxRate = (float) ($item->tax ?? 0);

                                $discountAmount = $lineTotal * ($discountRate / 100);
                                $taxableAmount = $lineTotal - $discountAmount;
                                $taxAmount = $taxableAmount * ($taxRate / 100);

                                $lineGrandTotal = $taxableAmount + $taxAmount;
                            @endphp
                            <td>Rs{{ number_format($lineGrandTotal, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                <div class="footer-gap"></div>

                <div class="summary" style="margin-bottom: 24px;">
                    <div>
                        <span>Sub Total</span>
                        <span>Rs{{ number_format($record->subtotal, 2) }}</span>
                    </div>

                    <div>
                        <span>Total Discount</span>
                        <span>Rs{{ number_format($totalDiscount, 2) }}</span>
                    </div>

                    <div>
                        <span>Total Tax</span>
                        <span>Rs{{ number_format($totalTax, 2) }}</span>
                    </div>

                    <div>
                        <span>Paid Amount</span>
                        <span>Rs{{ number_format($paidAmount, 2) }}</span>
                    </div>

                    @if($remainingAmount > 0)
                        <div>
                            <span>Remaining Amount</span>
                            <span>Rs{{ number_format($remainingAmount, 2) }}</span>
                        </div>
                    @endif

                    <div class="grand">
                        <span>Grand Total</span>
                        <span>Rs{{ number_format($record->total_amount, 2) }}</span>
                    </div>

                </div>

                @if($record->notes)
                    <div class="notes">
                        <strong>Notes</strong><br>
                        {{ $record->notes }}
                    </div>
                @endif

                @if($showDefaultFooter)
                    <div class="contact-us">
                        <strong>Contact Us</strong><br>
                        <strong>{{ $contactNumber }}</strong>
                    </div>
                @endif

                @if(! empty($footerGroups))
                    <div class="contact-us">
                        @foreach($footerGroups as $group)
                            <strong>{{ $group['group_name'] }}</strong><br>
                            @foreach($group['fields'] as $field)
                                @continue(($field['value_type'] ?? null) === 'business_logo')
                                {{ $field['label'] }}: {{ $field['value'] }}<br>
                            @endforeach
                            @if(! $loop->last)
                                <br>
                            @endif
                        @endforeach
                    </div>
                @endif
                </div>
            </div>
        </section>
</div>

<script>
    (function () {
        const templateForm = document.getElementById('invoice-template-form');
        const headerSelect = document.getElementById('invoice-header-select');
        const footerSelect = document.getElementById('invoice-footer-select');
        const prevInvoiceArrow = document.getElementById('invoice-prev-arrow');
        const nextInvoiceArrow = document.getElementById('invoice-next-arrow');
        const printSlipButton = document.getElementById('print-slip-button');
        const slipCounter = document.getElementById('slip-counter');
        const slipPrintNumber = document.getElementById('slip-print-number');
        const slipNumberUrl = @js(route('invoices.slip-number', ['type' => $type, 'id' => $record->id]));

        const resolveCsrfToken = () => {
            const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (metaToken) {
                return metaToken;
            }

            const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
            return match ? decodeURIComponent(match[1]) : '';
        };

        const submitTemplateForm = () => {
            if (templateForm) {
                templateForm.submit();
            }
        };

        headerSelect?.addEventListener('change', submitTemplateForm);
        footerSelect?.addEventListener('change', submitTemplateForm);

        printSlipButton?.addEventListener('click', async () => {
            const csrfToken = resolveCsrfToken();

            try {
                const response = await fetch(slipNumberUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    credentials: 'same-origin',
                });

                const data = await response.json().catch(() => ({}));

                if (! response.ok) {
                    const message = data.message
                        ?? (response.status === 401
                            ? 'Your session has expired. Please log in again and reopen this invoice.'
                            : 'Could not assign today\'s slip number. Please try again.');

                    window.alert(message);
                    return;
                }

                if (slipPrintNumber) {
                    slipPrintNumber.textContent = String(data.number ?? '');
                }

                slipCounter?.classList.add('is-ready');
                slipCounter?.removeAttribute('hidden');
            } catch (error) {
                window.alert('Could not assign today\'s slip number. Please check your connection and try again.');
                return;
            }

            window.print();
        });

        prevInvoiceArrow?.addEventListener('click', () => {
            const href = prevInvoiceArrow.dataset.href;
            if (href) {
                window.location.href = href;
            }
        });

        nextInvoiceArrow?.addEventListener('click', () => {
            const href = nextInvoiceArrow.dataset.href;
            if (href) {
                window.location.href = href;
            }
        });

        document.addEventListener('keydown', (event) => {
            const target = event.target;
            const tagName = target?.tagName?.toLowerCase();
            const isTypingContext = target?.isContentEditable || ['input', 'textarea', 'select'].includes(tagName);

            if (isTypingContext) {
                return;
            }

            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                const href = prevInvoiceArrow?.dataset.href;
                if (href) {
                    window.location.href = href;
                }
            }

            if (event.key === 'ArrowRight') {
                event.preventDefault();
                const href = nextInvoiceArrow?.dataset.href;
                if (href) {
                    window.location.href = href;
                }
            }
        });
    })();
</script>

</body>
</html>
