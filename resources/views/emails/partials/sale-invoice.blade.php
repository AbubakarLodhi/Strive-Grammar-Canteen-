@php
    $sale = $sale->loadMissing(['items.product', 'items.variants.variant', 'customer', 'merchant', 'merchant.settings', 'payments']);

    $totalDiscount = 0;
    $totalTax = 0;

    foreach ($sale->items as $item) {
        $lineTotal = (float) ($item->line_total ?? 0);
        $discountRate = (float) ($item->discount ?? 0);
        $taxRate = (float) ($item->tax ?? 0);

        $discountAmount = $lineTotal * ($discountRate / 100);
        $taxableAmount = $lineTotal - $discountAmount;
        $taxAmount = $taxableAmount * ($taxRate / 100);

        $totalDiscount += $discountAmount;
        $totalTax += $taxAmount;
    }

    $paidAmount = (float) ($sale->paid_amount ?? 0);
    $remainingAmount = (float) ($sale->due_amount ?? 0);
    $paymentHistory = ($sale->payments ?? collect())
        ->sortBy([
            ['payment_date', 'asc'],
            ['created_at', 'asc'],
        ])
        ->values();

    $settings = $sale->merchant?->settings;
    $normalizeGroups = function (?array $groups): array {
        $groups = is_array($groups) ? $groups : [];

        return collect($groups)
            ->map(function ($group) {
                $groupName = trim((string) ($group['group_name'] ?? ''));
                $fields = collect($group['fields'] ?? [])
                    ->map(function ($field) {
                        $label = trim((string) ($field['label'] ?? ''));
                        $value = trim((string) ($field['value'] ?? ''));

                        if ($label === '' || $value === '') {
                            return null;
                        }

                        return compact('label', 'value');
                    })
                    ->filter()
                    ->values()
                    ->all();

                if ($groupName === '' || empty($fields)) {
                    return null;
                }

                return [
                    'group_name' => $groupName,
                    'fields' => $fields,
                ];
            })
            ->filter()
            ->values()
            ->all();
    };

    $headerGroups = $normalizeGroups($settings?->invoice_header_groups);
    $footerGroups = $normalizeGroups($settings?->invoice_footer_groups);
@endphp

<div style="font-family:Arial, sans-serif; color:#0f172a;">
    @if(! empty($headerGroups))
        <div style="margin-bottom: 14px; font-size: 12px; color: #475569;">
            @foreach($headerGroups as $group)
                <div style="margin-bottom: 8px;">
                    <strong style="color:#0f172a;">{{ $group['group_name'] }}</strong><br>
                    @foreach($group['fields'] as $field)
                        {{ $field['label'] }}: {{ $field['value'] }}<br>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    <table style="width:100%; border-collapse:collapse; font-size:12px;">
        <thead>
        <tr style="border-bottom:1px solid #e2e8f0; text-align:left;">
            <th style="padding:6px 0;">#</th>
            <th style="padding:6px 0;">Product</th>
            <th style="padding:6px 0;">Price</th>
            <th style="padding:6px 0;">Qty</th>
            <th style="padding:6px 0;">Subtotal</th>
            <th style="padding:6px 0;">Discount</th>
            <th style="padding:6px 0;">Tax</th>
            <th style="padding:6px 0;">Total</th>
        </tr>
        </thead>
        <tbody>
        @foreach($sale->items as $i => $item)
            @php
                $lineTotal = (float) ($item->line_total ?? 0);
                $discountRate = (float) ($item->discount ?? 0);
                $taxRate = (float) ($item->tax ?? 0);

                $discountAmount = $lineTotal * ($discountRate / 100);
                $taxableAmount = $lineTotal - $discountAmount;
                $taxAmount = $taxableAmount * ($taxRate / 100);

                $lineGrandTotal = $taxableAmount + $taxAmount;
            @endphp
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:6px 0;">{{ $i + 1 }}</td>
                <td style="padding:6px 0;">
                    {{ $item->product?->name }}
                    @if($item->variants->first())
                        <div style="font-size:11px; color:#64748b;">
                            {{ $item->variants->first()->variant?->name }}
                        </div>
                    @endif
                </td>
                <td style="padding:6px 0;">Rs{{ number_format($item->unit_price, 2) }}</td>
                <td style="padding:6px 0;">{{ $item->quantity }}</td>
                <td style="padding:6px 0;">Rs{{ number_format($item->line_total, 2) }}</td>
                <td style="padding:6px 0;">{{ number_format((float) ($item->discount ?? 0), 2) }}%</td>
                <td style="padding:6px 0;">{{ number_format((float) ($item->tax ?? 0), 2) }}%</td>
                <td style="padding:6px 0;">Rs{{ number_format($lineGrandTotal, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;">
        <tr>
            <td></td>
            <td width="320">
                <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px; border-collapse:collapse;">

                    <tr>
                        <td style="padding:10px 0; color:#475569;">
                            Net Total
                        </td>
                        <td style="padding:10px 0; text-align:right;">
                            Rs {{ number_format($sale->subtotal, 2) }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:10px 0; color:#475569;">
                            Total Discount
                        </td>
                        <td style="padding:10px 0; text-align:right;">
                            Rs {{ number_format($totalDiscount, 2) }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:10px 0; color:#475569;">
                            Total Tax
                        </td>
                        <td style="padding:10px 0; text-align:right;">
                            Rs {{ number_format($totalTax, 2) }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:10px 0; color:#475569;">
                            Paid Amount
                        </td>
                        <td style="padding:10px 0; text-align:right;">
                            Rs {{ number_format($paidAmount, 2) }}
                        </td>
                    </tr>

                    @if($remainingAmount > 0)
                        <tr>
                            <td style="padding:10px 0; color:#475569;">
                                Remaining Amount
                            </td>
                            <td style="padding:10px 0; text-align:right;">
                                Rs {{ number_format($remainingAmount, 2) }}
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td colspan="2" style="padding-top:10px;"></td>
                    </tr>
                    <tr>
                        <td colspan="2" style="padding-top:8px;">
                            <div style="
                            background:#1B4F72;
                            color:#ffffff;
                            padding:12px;
                            border-radius:8px;
                            font-weight:700;
                            font-size:14px;
                        ">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td>Grand Total</td>
                                        <td style="text-align:right;">
                                            Rs {{ number_format($sale->total_amount, 2) }}
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if(! empty($footerGroups))
        <div style="margin-top: 16px; font-size: 12px; color: #475569;">
            @foreach($footerGroups as $group)
                <div style="margin-bottom: 8px;">
                    <strong style="color:#0f172a;">{{ $group['group_name'] }}</strong><br>
                    @foreach($group['fields'] as $field)
                        {{ $field['label'] }}: {{ $field['value'] }}<br>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    @if($paymentHistory->isNotEmpty())
        <div style="margin-top: 14px; font-size: 12px; color: #475569;">
            <strong style="color:#0f172a;">Payment History</strong>
            <table style="width:100%; border-collapse:collapse; margin-top:6px; font-size:12px;">
                <thead>
                <tr style="border-bottom:1px solid #e2e8f0; text-align:left;">
                    <th style="padding:6px 0;">Date</th>
                    <th style="padding:6px 0;">Type</th>
                    <th style="padding:6px 0;">Amount</th>
                </tr>
                </thead>
                <tbody>
                @foreach($paymentHistory as $payment)
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:6px 0;">{{ $payment->payment_date?->format('d/m/Y') ?? '—' }}</td>
                        <td style="padding:6px 0;">{{ ucfirst((string) ($payment->entry_type ?? 'payment')) }}</td>
                        <td style="padding:6px 0;">Rs{{ number_format((float) ($payment->amount ?? 0), 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
