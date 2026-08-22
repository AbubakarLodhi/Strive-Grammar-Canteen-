<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    @php
        $merchant = $record->merchant;
        $settings = $merchant?->settings;
        $themePrimary = $settings?->primary_color ?? config('branding.colors.primary', '#1B4F72');
        $statusLabel = $record->status?->label() ?? (string) $record->status;
        $conditionLabel = $record->condition?->label() ?? (string) $record->condition;
        $formatMoney = fn (?float $amount): string => $amount === null ? '—' : 'Rs '.number_format($amount, 2);
        $formatDate = fn ($date): string => $date?->format('d/m/Y') ?? '—';
    @endphp
    <title>Asset {{ $record->asset_code }}</title>
    <style>
        :root {
            --ink: #1f2937;
            --muted: #6b7280;
            --line: #e5e7eb;
            --accent: {{ $themePrimary }};
            --bg: #f3f4f6;
        }

        body {
            font-family: "Helvetica Neue", Arial, "Noto Sans", sans-serif;
            background: var(--bg);
            margin: 0;
            padding: 30px 0;
            color: var(--ink);
        }

        .sheet {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            padding: 48px 54px 60px;
            border-radius: 24px;
            box-shadow: 0 10px 40px rgba(15, 23, 42, 0.12);
            box-sizing: border-box;
            min-height: 100vh;
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
            max-width: 50%;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.5;
        }

        .brand img {
            height: 44px;
            width: auto;
        }

        .doc-title {
            text-align: right;
            max-width: 50%;
        }

        .doc-title h1 {
            margin: 0 0 8px;
            font-size: 24px;
            letter-spacing: 1px;
            font-weight: 600;
            color: var(--accent);
        }

        .doc-title .subtitle {
            font-size: 12px;
            color: var(--muted);
        }

        .badges {
            margin-top: 10px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            background: #E8EEF4;
            color: #3730a3;
        }

        .badge.status-active { background: #ecfdf5; color: #047857; }
        .badge.status-in_maintenance { background: #fff7ed; color: #c2410c; }
        .badge.status-disposed { background: #f3f4f6; color: #4b5563; }
        .badge.status-lost { background: #fef2f2; color: #b91c1c; }

        .divider {
            margin: 26px 0 18px;
            border-top: 1px solid var(--line);
        }

        .section-title {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--accent);
            margin: 0 0 10px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 28px;
            font-size: 13px;
        }

        .info-row {
            display: grid;
            grid-template-columns: 130px minmax(0, 1fr);
            gap: 10px;
            align-items: baseline;
        }

        .info-row .label {
            color: var(--muted);
        }

        .info-row .value {
            color: var(--ink);
            font-weight: 500;
            word-break: break-word;
        }

        .section {
            margin-bottom: 22px;
        }

        .notes {
            font-size: 13px;
            color: var(--muted);
            line-height: 1.6;
            white-space: pre-wrap;
        }

        .financial-highlight {
            margin-top: 8px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .financial-card {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px 16px;
            background: #fafafa;
        }

        .financial-card .label {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .financial-card .amount {
            margin-top: 6px;
            font-size: 20px;
            font-weight: 700;
            color: var(--accent);
        }

        .actions {
            position: fixed;
            top: 24px;
            right: 24px;
            display: flex;
            gap: 10px;
            z-index: 10;
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

        .side-arrow {
            position: fixed;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            border-radius: 999px;
            border: 1px solid #d1d5db;
            background: rgba(255, 255, 255, 0.95);
            color: #111827;
            font-size: 18px;
            cursor: pointer;
            z-index: 10;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12);
        }

        .side-arrow:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }

        .side-arrow.left { left: 16px; }
        .side-arrow.right { right: 16px; }

        @media print {
            body { background: #fff; padding: 0; }
            .sheet { box-shadow: none; border-radius: 0; min-height: auto; padding: 24px; }
            .actions, .side-arrow { display: none !important; }
        }
    </style>
</head>
<body>
<div class="actions">
    <button class="btn" type="button" onclick="window.print()">Print</button>
    <button class="btn secondary" type="button" onclick="window.location.href = @js($closeUrl)">Close</button>
</div>

<button
    type="button"
    id="asset-prev-arrow"
    class="side-arrow left"
    aria-label="Previous Asset"
    data-href="{{ $previousAssetUrl ?? '' }}"
    @if(empty($previousAssetUrl)) disabled @endif
>&lt;</button>

<button
    type="button"
    id="asset-next-arrow"
    class="side-arrow right"
    aria-label="Next Asset"
    data-href="{{ $nextAssetUrl ?? '' }}"
    @if(empty($nextAssetUrl)) disabled @endif
>&gt;</button>

<div class="sheet">
    <div class="header">
        <div class="brand">
            @if($merchant?->logo)
                <img src="{{ asset('storage/'.$merchant->logo->photo_url) }}" alt="{{ $merchant->name }}">
            @else
                <strong style="color: var(--ink); font-size: 16px;">{{ $merchant?->name }}</strong>
            @endif
            <div>
                {{ $merchant?->name }}<br>
                @if($merchant?->address){{ $merchant->address }}<br>@endif
                @if($merchant?->phone){{ $merchant->phone }}<br>@endif
                @if($merchant?->email){{ $merchant->email }}@endif
            </div>
        </div>

        <div class="doc-title">
            <h1>ASSET REGISTER</h1>
            <div class="subtitle">{{ $record->name }}</div>
            <div class="subtitle">Code: {{ $record->asset_code }}</div>
            <div class="badges">
                <span class="badge">Type: {{ $record->assetType?->name ?? '—' }}</span>
                <span class="badge status-{{ $record->status?->value ?? 'active' }}">{{ $statusLabel }}</span>
                <span class="badge">{{ $conditionLabel }}</span>
            </div>
        </div>
    </div>

    <div class="divider"></div>

    <div class="section">
        <div class="section-title">Asset Identity</div>
        <div class="info-grid">
            <div class="info-row"><span class="label">Asset Name</span><span class="value">{{ $record->name }}</span></div>
            <div class="info-row"><span class="label">Asset Code</span><span class="value">{{ $record->asset_code }}</span></div>
            <div class="info-row"><span class="label">Asset Type</span><span class="value">{{ $record->assetType?->name ?? '—' }}</span></div>
            <div class="info-row"><span class="label">Serial Number</span><span class="value">{{ $record->serial_number ?: '—' }}</span></div>
            <div class="info-row"><span class="label">Model Number</span><span class="value">{{ $record->model_number ?: '—' }}</span></div>
            <div class="info-row"><span class="label">Manufacturer</span><span class="value">{{ $record->manufacturer ?: '—' }}</span></div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Location & Assignment</div>
        <div class="info-grid">
            <div class="info-row"><span class="label">Business</span><span class="value">{{ $record->business?->name ?? '—' }}</span></div>
            <div class="info-row"><span class="label">Branch</span><span class="value">{{ $record->branch?->name ?? '—' }}</span></div>
            <div class="info-row"><span class="label">Location</span><span class="value">{{ $record->location ?: '—' }}</span></div>
            <div class="info-row"><span class="label">Assigned To</span><span class="value">{{ $record->assignedUser?->name ?? '—' }}</span></div>
            <div class="info-row"><span class="label">Supplier</span><span class="value">{{ $record->vendor?->name ?? '—' }}</span></div>
            <div class="info-row"><span class="label">Recorded By</span><span class="value">{{ $record->createdBy?->name ?? $merchant?->name ?? '—' }}</span></div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Financial & Lifecycle</div>
        <div class="financial-highlight">
            <div class="financial-card">
                <div class="label">Purchase Cost</div>
                <div class="amount">{{ $formatMoney($record->purchase_cost !== null ? (float) $record->purchase_cost : null) }}</div>
            </div>
            <div class="financial-card">
                <div class="label">Current Value</div>
                <div class="amount">{{ $formatMoney($record->current_value !== null ? (float) $record->current_value : null) }}</div>
            </div>
        </div>
        <div class="info-grid" style="margin-top: 14px;">
            <div class="info-row"><span class="label">Purchase Date</span><span class="value">{{ $formatDate($record->purchase_date) }}</span></div>
            <div class="info-row"><span class="label">Warranty Expiry</span><span class="value">{{ $formatDate($record->warranty_expiry) }}</span></div>
            <div class="info-row"><span class="label">Status</span><span class="value">{{ $statusLabel }}</span></div>
            <div class="info-row"><span class="label">Condition</span><span class="value">{{ $conditionLabel }}</span></div>
        </div>
    </div>

    @if(filled($record->description))
        <div class="section">
            <div class="section-title">Description</div>
            <div class="notes">{{ $record->description }}</div>
        </div>
    @endif

    @if(filled($record->notes))
        <div class="section">
            <div class="section-title">Notes</div>
            <div class="notes">{{ $record->notes }}</div>
        </div>
    @endif

    @if($record->attachment?->photo_url)
        <div class="section">
            <div class="section-title">Attached File</div>
            <div class="notes">
                <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($record->attachment->photo_url) }}" target="_blank" rel="noopener">
                    {{ basename($record->attachment->photo_url) }}
                </a>
            </div>
        </div>
    @endif
</div>

<script>
    (function () {
        const prevArrow = document.getElementById('asset-prev-arrow');
        const nextArrow = document.getElementById('asset-next-arrow');

        const navigate = (button) => {
            const href = button?.dataset?.href;
            if (href) {
                window.location.href = href;
            }
        };

        prevArrow?.addEventListener('click', () => navigate(prevArrow));
        nextArrow?.addEventListener('click', () => navigate(nextArrow));

        document.addEventListener('keydown', (event) => {
            const target = event.target;
            const tagName = target?.tagName?.toLowerCase();
            if (tagName === 'input' || tagName === 'textarea' || tagName === 'select') {
                return;
            }

            if (event.key === 'ArrowLeft' && prevArrow && !prevArrow.disabled) {
                navigate(prevArrow);
            }

            if (event.key === 'ArrowRight' && nextArrow && !nextArrow.disabled) {
                navigate(nextArrow);
            }
        });
    })();
</script>
</body>
</html>
