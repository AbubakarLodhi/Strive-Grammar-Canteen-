@php
    $merchant = $merchant ?? $sale->merchant;
    $customer = $sale->customer;
    $isAdmin = $recipientRole === 'admin';
    $companyName = $merchant?->name ?? 'Our company';
    $amountDue = (float) ($sale->due_amount ?? 0);
    $dueDateFormatted = $sale->due_date?->format('d M, Y') ?? '—';
    $supportEmail = $merchant?->email;
    $settings = $merchant?->settings;
    $themeBlue = $settings?->primary_color ?? '#1B4F72';
    $themeGreen = $settings?->success_color ?? '#0bb783';
    $themeTeal = '#1bc5bd';
    $grandTotalBackground = "linear-gradient(135deg, {$themeBlue} 0%, {$themeGreen} 62%, {$themeTeal} 100%)";
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment request — {{ $sale->sale_no }}</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:560px;">
                @if(!empty($merchantLogoUrl))
                    <tr>
                        <td style="padding-bottom:24px;">
                            <img src="{{ $merchantLogoUrl }}" alt="{{ $companyName }}" style="max-height:56px;max-width:200px;display:block;">
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding-bottom:8px;">
                        <p style="margin:0;font-size:32px;font-weight:700;line-height:1.15;color:#111827;letter-spacing:-0.02em;">
                            {{ $companyName }}
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding-bottom:4px;">
                        <p style="margin:0;font-size:16px;line-height:1.5;color:#374151;">
                            {{ $isAdmin ? 'is following up on a payment of' : 'is requesting a payment of' }}
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding-bottom:24px;">
                        <p style="margin:0;font-size:36px;font-weight:700;line-height:1.2;color:#111827;">
                            Rs {{ number_format($amountDue, 2) }}
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 0;border-top:2px dashed #fca5a5;border-bottom:2px dashed #fca5a5;">
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-size:14px;line-height:1.6;">
                            <tr>
                                <td style="padding:8px 0;color:#6b7280;width:40%;">Reference #</td>
                                <td align="right" style="padding:8px 0;color:#111827;font-weight:600;">{{ $sale->sale_no }}</td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0;color:#6b7280;">Customer</td>
                                <td align="right" style="padding:8px 0;color:#111827;font-weight:600;">{{ $customer?->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0;color:#6b7280;">Due date</td>
                                <td align="right" style="padding:8px 0;color:#111827;font-weight:600;">{{ $dueDateFormatted }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding-top:28px;">
                        @include('emails.partials.sale-line-items-summary', [
                            'sale' => $sale,
                            'grandTotalBackground' => $grandTotalBackground,
                        ])

                        <p style="margin:20px 0 16px;font-size:15px;line-height:1.6;color:#374151;">
                            {{ $emailCaption }}
                        </p>

                        <p style="margin:0 0 28px;font-size:14px;line-height:1.6;color:#6b7280;">
                            {{ $pdfFooterLine }}
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding-top:24px;border-top:1px solid #e5e7eb;">
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                            <tr>
                                <td style="vertical-align:top;padding-right:16px;">
                                    <p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#111827;">Have questions?</p>
                                    @if($supportEmail)
                                        <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">
                                            If you need assistance, reach out to our support team at
                                            <a href="mailto:{{ $supportEmail }}" style="color:#2563eb;text-decoration:none;">{{ $supportEmail }}</a>
                                        </p>
                                    @elseif($merchant?->phone)
                                        <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">
                                            Contact us at {{ $merchant->phone }}
                                        </p>
                                    @endif
                                </td>
                                <td align="right" style="vertical-align:bottom;white-space:nowrap;">
                                    <p style="margin:0;font-size:11px;color:#9ca3af;line-height:1.4;">
                                        powered by<br>
                                        <span style="font-size:14px;font-weight:700;color:#ea580c;letter-spacing:0.04em;">{{ $companyName }}</span>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
