@php
    use App\Filament\Resources\Customers\CustomerResource;
    use App\Filament\Resources\Sales\SaleResource;
    use Filament\Facades\Filament;

    $user = Filament::auth()->user();
    $merchant = $user instanceof \App\Models\Merchant ? $user : $user?->merchant;
    $settings = $merchant?->settings;
    $posPrimary = $settings?->primary_color ?? '#1B4F72';
    $posSuccess = $settings?->success_color ?? $posPrimary;

    $salesIndexUrl = SaleResource::getUrl('index');
    $customerCreateUrl = CustomerResource::getUrl('create');
    $invoiceBase = url('/invoices/sale');
    $posCustomers = $this->getPosCustomers();
@endphp

<x-filament-panels::page fullHeight>
    <style>
        .pos-root {
            --pos-primary: var(--primary-600, {{ $posPrimary }});
            --pos-primary-500: var(--primary-500, {{ $posPrimary }});
            --pos-primary-50: var(--primary-50, color-mix(in srgb, {{ $posPrimary }} 12%, #fff));
            --pos-success: var(--success-600, {{ $posSuccess }});
            --pos-border: var(--gray-200, #e5e7eb);
            --pos-muted: var(--gray-500, #6b7280);
            --pos-text: var(--gray-950, #111827);
            --pos-bg: var(--gray-100, #f3f4f6);
            --pos-card: var(--white, #ffffff);
            --pos-surface: var(--gray-50, #f9fafb);
            --pos-radius: 0;
            --pos-danger: var(--danger-600, #dc2626);
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .dark .pos-root {
            --pos-primary-50: color-mix(in srgb, var(--pos-primary) 14%, var(--gray-900, #111827));
            --pos-border: var(--gray-700, #374151);
            --pos-muted: var(--gray-400, #9ca3af);
            --pos-text: var(--gray-50, #f9fafb);
            --pos-bg: var(--gray-950, #030712);
            --pos-card: var(--gray-900, #111827);
            --pos-surface: var(--gray-800, #1f2937);
        }

        .pos-wrap {
            display: grid;
            grid-template-columns: 1fr min(440px, 34vw);
            gap: 0;
            padding: 0;
            background: var(--pos-bg);
            flex: 1 1 auto;
            min-height: 0;
            height: 100%;
            box-sizing: border-box;
        }

        .pos-left {
            background: var(--pos-card);
            border-radius: 0;
            border: none;
            border-right: 1px solid var(--pos-border);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            overflow: hidden;
            min-height: 0;
        }

        .pos-search-bar input {
            width: 100%;
            padding: 11px 14px 11px 38px;
            border: 1px solid var(--pos-border);
            border-radius: 10px;
            font-size: 14px;
            outline: none;
            box-sizing: border-box;
            background: var(--pos-surface) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%239ca3af'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z'/%3E%3C/svg%3E") 12px center / 18px no-repeat;
            color: var(--pos-text);
        }

        .pos-search-bar input:focus {
            border-color: var(--pos-primary);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--pos-primary) 18%, transparent);
        }

        .pos-categories {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 2px;
            flex-shrink: 0;
        }

        .cat-btn {
            border: 1px solid var(--pos-border);
            background: var(--pos-card);
            padding: 7px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
            color: var(--pos-text);
            -webkit-text-fill-color: currentColor;
            transition: all 0.15s;
        }

        .cat-btn.active,
        .cat-btn:hover {
            background: var(--pos-primary);
            border-color: var(--pos-primary);
            color: #ffffff;
            -webkit-text-fill-color: #ffffff;
        }

        .pos-products {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(155px, 1fr));
            gap: 12px;
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            padding: 2px;
            align-content: start;
        }

        .pos-prod-card {
            background: var(--pos-card);
            border-radius: 12px;
            padding: 12px;
            border: 1px solid var(--pos-border);
            transition: border-color 0.15s, box-shadow 0.15s;
            cursor: pointer;
            position: relative;
        }

        .pos-prod-card.is-disabled {
            opacity: 0.55;
            cursor: not-allowed;
            filter: grayscale(0.35);
        }

        .pos-prod-card.is-disabled:hover {
            border-color: var(--pos-border);
            box-shadow: none;
        }

        .pos-prod-stock-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: color-mix(in srgb, var(--pos-danger) 12%, #fff);
            color: var(--pos-danger);
            font-size: 10px;
            font-weight: 700;
            padding: 3px 7px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .pos-prod-stock-badge.in-stock {
            background: color-mix(in srgb, var(--pos-success) 12%, #fff);
            color: var(--pos-success);
            text-transform: none;
        }

        .pos-prod-card:hover {
            border-color: var(--pos-primary);
            box-shadow: 0 8px 20px color-mix(in srgb, var(--pos-primary) 12%, transparent);
        }

        .pos-card-qty-ctrl {
            position: absolute;
            bottom: 8px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            gap: 4px;
            background: var(--pos-primary);
            border-radius: 8px;
            padding: 2px 4px;
            box-shadow: 0 4px 12px color-mix(in srgb, var(--pos-primary) 35%, transparent);
        }

        .pos-card-qty-btn {
            width: 22px;
            height: 22px;
            border: none;
            border-radius: 6px;
            background: rgba(255,255,255,0.2);
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .pos-card-qty-val {
            font-size: 12px;
            font-weight: 700;
            color: #fff;
            min-width: 18px;
            text-align: center;
        }

        .pos-prod-icon {
            width: 100%;
            height: 88px;
            background: var(--pos-surface);
            border-radius: 10px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .pos-prod-name { font-size: 13px; font-weight: 600; color: var(--pos-text); line-height: 1.3; }
        .pos-prod-sku { font-size: 11px; color: #9ca3af; margin-top: 2px; }
        .pos-prod-price { font-size: 12px; color: var(--pos-primary); font-weight: 700; margin-top: 6px; }

        .pos-right {
            background: var(--pos-card);
            border-radius: 0;
            border: none;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 0;
        }

        .pos-panel-head {
            padding: 14px 16px;
            border-bottom: 1px solid var(--pos-border);
            background: linear-gradient(135deg, var(--pos-primary-50), var(--pos-card));
        }

        .pos-panel-head h2 {
            font-size: 15px;
            font-weight: 700;
            color: var(--pos-text);
            margin: 0 0 10px;
        }

        .pos-meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .pos-field label {
            display: block;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--pos-muted);
            margin-bottom: 3px;
        }

        .pos-field input,
        .pos-field select,
        .pos-field textarea {
            width: 100%;
            padding: 7px 9px;
            border: 1px solid var(--pos-border);
            border-radius: 8px;
            font-size: 12px;
            color: var(--pos-text);
            background: var(--pos-card);
            box-sizing: border-box;
        }

        .pos-field input:focus,
        .pos-field select:focus,
        .pos-field textarea:focus {
            outline: none;
            border-color: var(--pos-primary);
        }

        .pos-customer-row {
            display: flex;
            gap: 6px;
            align-items: flex-end;
            padding: 10px 14px;
            border-bottom: 1px solid var(--pos-border);
        }

        .pos-customer-row .pos-field { flex: 1; min-width: 0; }

        .pos-add-customer {
            flex-shrink: 0;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid var(--pos-border);
            background: var(--pos-card);
            color: var(--pos-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: background 0.15s;
        }

        .pos-add-customer:hover { background: var(--pos-primary-50); }

        .pos-disc-bar {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 0 14px 8px;
            border-bottom: 1px solid var(--pos-border);
        }

        .pos-disc-bar span { font-size: 11px; color: var(--pos-muted); }

        .pos-mode-btn {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid var(--pos-border);
            background: var(--pos-card);
            color: var(--pos-text);
            -webkit-text-fill-color: currentColor;
            cursor: pointer;
        }

        .pos-mode-btn.active {
            background: var(--pos-primary);
            border-color: var(--pos-primary);
            color: #ffffff;
            -webkit-text-fill-color: #ffffff;
        }

        .pos-cart-header {
            display: grid;
            grid-template-columns: 1fr 72px 64px 24px;
            gap: 6px;
            padding: 6px 14px;
            background: var(--pos-surface);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--pos-muted);
            border-bottom: 1px solid var(--pos-border);
            flex-shrink: 0;
        }

        .pos-cart-body { flex: 1; overflow-y: auto; min-height: 0; }

        .pos-cart-item {
            display: grid;
            grid-template-columns: 1fr 72px 64px 24px;
            gap: 6px;
            align-items: start;
            padding: 10px 14px;
            border-bottom: 1px solid var(--pos-border);
            font-size: 12px;
        }

        .pos-cart-item-name { font-weight: 600; color: var(--pos-text); line-height: 1.3; }
        .pos-cart-item-meta { font-size: 10px; color: #9ca3af; margin-top: 2px; }

        .pos-qty-ctrl { display: flex; align-items: center; gap: 4px; justify-content: center; }

        .pos-qty-btn {
            width: 22px;
            height: 22px;
            border-radius: 6px;
            border: 1px solid var(--pos-border);
            background: var(--pos-card);
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--pos-text);
        }

        .pos-qty-val { font-size: 12px; min-width: 16px; text-align: center; font-weight: 600; color: var(--pos-text); }
        .pos-item-price { font-size: 12px; font-weight: 700; color: var(--pos-primary); text-align: right; }

        .pos-del-btn {
            border: none;
            background: transparent;
            color: var(--pos-danger);
            cursor: pointer;
            padding: 2px;
        }

        .pos-item-inputs {
            grid-column: 1 / -1;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding-top: 6px;
        }

        .pos-item-inputs .pos-field { flex: 0 0 auto; }
        .pos-item-inputs .pos-field input { width: 72px; }

        .pos-empty {
            padding: 32px 16px;
            text-align: center;
            color: #9ca3af;
            font-size: 12px;
        }

        .pos-error {
            margin: 8px 14px 0;
            padding: 8px 12px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            font-size: 12px;
            color: #b91c1c;
        }

        .pos-summary {
            padding: 10px 14px;
            background: var(--pos-surface);
            border-top: 1px solid var(--pos-border);
            font-size: 12px;
            flex-shrink: 0;
        }

        .pos-sum-row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            color: var(--pos-muted);
        }

        .pos-sum-row.total {
            font-weight: 700;
            font-size: 14px;
            color: var(--pos-text);
            padding-top: 8px;
            margin-top: 6px;
            border-top: 1px solid var(--pos-border);
        }

        .pos-sum-row.due span:last-child { color: var(--pos-danger); font-weight: 600; }

        .pos-payment {
            padding: 12px 14px;
            border-top: 1px solid var(--pos-border);
            flex-shrink: 0;
        }

        .pos-payment h3 {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--pos-muted);
            margin: 0 0 10px;
        }

        .pos-pay-methods {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin-bottom: 10px;
        }

        .pos-pay-method {
            padding: 8px;
            border-radius: 8px;
            border: 1px solid var(--pos-border);
            background: var(--pos-card);
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            color: var(--pos-text);
            -webkit-text-fill-color: currentColor;
        }

        .pos-pay-method.active {
            border-color: var(--pos-primary);
            background: var(--pos-primary);
            color: #ffffff;
            -webkit-text-fill-color: #ffffff;
        }

        .pos-quick-pay {
            display: flex;
            gap: 6px;
            margin: 8px 0;
        }

        .pos-quick-pay button {
            flex: 1;
            padding: 6px 8px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            border: 1px solid var(--pos-border);
            background: var(--pos-surface);
            color: var(--pos-text);
            -webkit-text-fill-color: currentColor;
        }

        .pos-quick-pay button:first-child {
            border-color: var(--pos-primary);
            color: #ffffff;
            background: var(--pos-primary);
            -webkit-text-fill-color: #ffffff;
        }

        .pos-notes {
            padding: 0 14px 10px;
            flex-shrink: 0;
        }

        .pos-actions {
            display: flex;
            gap: 8px;
            padding: 12px 14px;
            border-top: 1px solid var(--pos-border);
            background: var(--pos-card);
            flex-shrink: 0;
        }

        .pos-cancel-btn {
            flex: 1;
            padding: 10px;
            border-radius: 10px;
            border: 1px solid color-mix(in srgb, var(--pos-danger) 35%, transparent);
            background: var(--pos-card);
            color: var(--pos-danger);
            -webkit-text-fill-color: currentColor;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .pos-place-btn {
            flex: 2;
            padding: 10px;
            border-radius: 10px;
            border: none;
            background: linear-gradient(135deg, var(--pos-primary), var(--pos-success));
            color: #ffffff;
            -webkit-text-fill-color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .pos-place-btn span {
            color: inherit;
            -webkit-text-fill-color: inherit;
        }

        .pos-place-btn:disabled { opacity: 0.55; cursor: not-allowed; }

        .pos-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .pos-modal {
            background: #ffffff;
            color: #111827;
            color-scheme: light;
            border-radius: 16px;
            padding: 24px;
            width: 440px;
            max-width: 100%;
            box-shadow: 0 24px 48px rgba(0,0,0,0.15);
        }

        .pos-modal h3 {
            font-size: 16px;
            font-weight: 700;
            margin: 0 0 16px;
            color: #111827;
        }

        .pos-modal label {
            font-size: 12px;
            font-weight: 600;
            color: #4b5563;
            display: block;
            margin: 12px 0 4px;
        }

        .pos-modal select {
            width: 100%;
            padding: 9px 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 13px;
            background: #ffffff;
            color: #111827;
        }

        .pos-modal select option {
            background: #ffffff;
            color: #111827;
        }

        .pos-modal-actions { display: flex; gap: 8px; margin-top: 20px; }

        .pos-modal-cancel {
            flex: 1;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            background: #ffffff;
            color: #374151 !important;
            -webkit-text-fill-color: #374151 !important;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .pos-modal-add {
            flex: 2;
            padding: 10px;
            border: none;
            border-radius: 10px;
            background: var(--pos-primary);
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .pos-order-modal {
            background: #ffffff;
            color: #111827;
            color-scheme: light;
            border-radius: 18px;
            padding: 28px 24px;
            width: 380px;
            max-width: 100%;
            text-align: center;
            box-shadow: 0 24px 48px rgba(0,0,0,0.18);
        }

        .pos-order-modal h3 { color: #111827; }
        .pos-order-modal .sale-no { color: #6b7280; }

        .pos-order-modal .success-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: color-mix(in srgb, var(--pos-success) 15%, #fff);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .pos-order-modal h3 { font-size: 18px; font-weight: 700; margin: 0 0 4px; }
        .pos-order-modal .sale-no { font-size: 12px; color: #9ca3af; margin-bottom: 20px; }

        .pos-order-modal-btns { display: flex; gap: 10px; margin-bottom: 10px; }

        .pos-order-modal button {
            font-family: inherit;
            line-height: 1.25;
        }

        .pos-order-btn-invoice {
            flex: 1;
            padding: 11px;
            border-radius: 10px;
            border: none;
            background: var(--pos-primary);
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .pos-order-btn-sales {
            flex: 1;
            padding: 11px;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            background: #ffffff;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            color: #111827 !important;
            -webkit-text-fill-color: #111827 !important;
        }

        .pos-order-btn-another {
            width: 100%;
            padding: 10px;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            color: #374151 !important;
            -webkit-text-fill-color: #374151 !important;
        }

        /* Modals always use light surfaces — ignore dark-theme text tokens */
        .dark .pos-order-modal .pos-order-btn-sales {
            color: #111827 !important;
            -webkit-text-fill-color: #111827 !important;
        }

        .dark .pos-order-modal .pos-order-btn-another {
            color: #374151 !important;
            -webkit-text-fill-color: #374151 !important;
        }

        .dark .pos-order-modal .pos-order-btn-invoice {
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
        }

        .dark .pos-modal .pos-modal-cancel {
            color: #374151 !important;
            -webkit-text-fill-color: #374151 !important;
        }

        .dark .pos-modal .pos-modal-add {
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
        }

        /* Filament dark mode can force light button labels — reset inside POS */
        .dark .pos-root .pos-quick-pay button,
        .dark .pos-root .pos-pay-method,
        .dark .pos-root .pos-mode-btn {
            -webkit-text-fill-color: currentColor;
        }

        .pos-no-products { grid-column: 1 / -1; text-align: center; color: #9ca3af; padding: 48px 16px; font-size: 13px; }

        @media (max-width: 1024px) {
            .pos-wrap {
                grid-template-columns: 1fr;
                grid-template-rows: 1fr minmax(420px, 45vh);
            }
            .pos-left { min-height: 0; border-right: none; border-bottom: 1px solid var(--pos-border); }
            .pos-right { min-height: 0; }
        }
    </style>

    <div
        x-data="{
            showModal: false,
            showOrderModal: false,
            lastSaleId: null,
            lastSaleNo: null,
            modalProduct: null,
            modalVariant: null,
            modalBranch: null,
            products: [],
            variants: [],
            branches: [],
            categories: [],
            search: '',
            activeCategory: '',
            salesIndexUrl: @js($salesIndexUrl),
            invoiceBase: @js($invoiceBase),

            init() {
                this.loadCategories();
                this.loadProducts();
                window.addEventListener('pos-order-placed', (e) => {
                    this.lastSaleId = e.detail.saleId;
                    this.lastSaleNo = e.detail.saleNo;
                    this.showOrderModal = true;
                    this.loadProducts();
                });
                window.addEventListener('pos-products-refresh', () => this.loadProducts());
            },

            loadCategories() {
                fetch('/pos/categories').then(r => r.json()).then(d => { this.categories = d; });
            },

            loadProducts() {
                this.$wire.fetchPosProducts(this.search, this.activeCategory || null)
                    .then(d => { this.products = d; });
            },

            filterByCategory(categoryId) {
                this.activeCategory = categoryId;
                this.loadProducts();
            },

            loadVariants(productId, branchId = null) {
                this.modalVariant = null;
                this.variants = [];
                if (!productId) return;
                this.$wire.fetchPosVariants(productId, branchId || null)
                    .then(d => { this.variants = d; });
            },

            loadBranches(productId) {
                this.branches = [];
                if (!productId) return;
                fetch('/pos/branches?product_id=' + encodeURIComponent(productId))
                    .then(r => r.json())
                    .then(d => { this.branches = d; });
            },

            openModal(product) {
                if (!product?.in_stock && this.getProductQty(product.id) <= 0) return;
                this.modalProduct = product;
                this.modalVariant = null;
                this.modalBranch = null;
                this.variants = [];
                this.loadBranches(product.id);
                this.showModal = true;
            },

            onBranchChanged() {
                if (!this.modalProduct?.id || !this.modalBranch) {
                    this.variants = [];
                    this.modalVariant = null;
                    return;
                }
                this.loadVariants(this.modalProduct.id, this.modalBranch);
            },

            selectedVariantInStock() {
                if (!this.modalVariant) return false;
                let variant = this.variants.find(v => String(v.id) === String(this.modalVariant));
                return variant ? !!variant.in_stock : false;
            },

            confirmAdd() {
                if (!this.modalVariant || !this.modalBranch || !this.selectedVariantInStock()) return;
                $wire.posAddItem(this.modalProduct.id, this.modalVariant, this.modalBranch);
                this.showModal = false;
            },

            goToSales() { window.location.href = this.salesIndexUrl; },

            openInvoice() {
                if (this.lastSaleId) window.open(this.invoiceBase + '/' + this.lastSaleId, '_blank');
            },

            getProductQty(productId) {
                let total = 0;
                let cart = this.$wire.posCart || {};
                for (let k in cart) {
                    if (String(k).startsWith(String(productId) + '_')) {
                        total += parseInt(cart[k]?.quantity || 0);
                    }
                }
                return total;
            },

            getFirstCartKey(productId) {
                let cart = this.$wire.posCart || {};
                for (let k in cart) {
                    if (String(k).startsWith(String(productId) + '_')) return k;
                }
                return null;
            },

            cardIncrement(productId) {
                let k = this.getFirstCartKey(productId);
                if (k) this.$wire.posUpdateQty(k, 1);
            },

            cardDecrement(productId) {
                let k = this.getFirstCartKey(productId);
                if (k) this.$wire.posUpdateQty(k, -1);
            }
        }"
        x-init="init()"
        @pos-products-refresh.window="loadProducts()"
        class="pos-root"
    >
        <div class="pos-wrap">

            {{-- Products --}}
            <div class="pos-left">
                <div class="pos-search-bar">
                    <input type="text" placeholder="Search by name or SKU…"
                        x-model="search" @input.debounce.300ms="loadProducts()" />
                </div>

                <div class="pos-categories">
                    <button type="button" class="cat-btn" :class="activeCategory === '' ? 'active' : ''"
                        @click="filterByCategory('')">All</button>
                    <template x-for="cat in categories" :key="cat.id">
                        <button type="button" class="cat-btn"
                            :class="activeCategory === cat.id ? 'active' : ''"
                            @click="filterByCategory(cat.id)"
                            x-text="cat.name"></button>
                    </template>
                </div>

                <div class="pos-products">
                    <template x-for="product in products" :key="product.id">
                        <div
                            class="pos-prod-card"
                            :class="{ 'is-disabled': !product.in_stock && getProductQty(product.id) <= 0 }"
                            @click="openModal(product)"
                        >
                            <span
                                class="pos-prod-stock-badge"
                                :class="{ 'in-stock': product.in_stock && product.tracks_inventory }"
                                x-show="product.tracks_inventory"
                                x-text="product.in_stock ? ('Stock: ' + parseFloat(product.stock || 0)) : 'Out of stock'"
                            ></span>
                            <div class="pos-prod-icon">
                                <template x-if="product.image">
                                    <img :src="product.image" alt="" style="width:100%;height:100%;object-fit:cover;" />
                                </template>
                                <template x-if="!product.image">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#9ca3af" style="width:32px;height:32px">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                                    </svg>
                                </template>
                            </div>
                            <div class="pos-prod-name" x-text="product.name"></div>
                            <div class="pos-prod-sku" x-text="product.sku ? 'SKU: ' + product.sku : ''"></div>
                            <div class="pos-prod-price" x-text="'PKR ' + parseFloat(product.selling_price || 0).toLocaleString()"></div>

                            <div class="pos-card-qty-ctrl" x-show="getProductQty(product.id) > 0" @click.stop>
                                <button type="button" class="pos-card-qty-btn" @click.stop="cardDecrement(product.id)">−</button>
                                <span class="pos-card-qty-val" x-text="getProductQty(product.id)"></span>
                                <button type="button" class="pos-card-qty-btn" @click.stop="cardIncrement(product.id)">+</button>
                            </div>
                        </div>
                    </template>
                    <template x-if="products.length === 0">
                        <div class="pos-no-products">No products match your search.</div>
                    </template>
                </div>
            </div>

            {{-- Order panel --}}
            <div class="pos-right">

                <div class="pos-panel-head">
                    <h2>New sale</h2>
                    <div class="pos-meta-grid">
                        <div class="pos-field">
                            <label>Sale number</label>
                            <input type="text" value="{{ $this->posSaleNo }}" readonly />
                        </div>
                        <div class="pos-field">
                            <label>Sale date</label>
                            <input type="date" wire:model.live="posSaleDate" />
                        </div>
                    </div>
                </div>

                <div class="pos-customer-row">
                    <div class="pos-field">
                        <label>Customer</label>
                        <select wire:model.live="posCustomerId">
                            <option value="">Select customer…</option>
                            @foreach ($posCustomers as $customer)
                                <option value="{{ $customer['id'] }}">{{ $customer['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <a href="{{ $customerCreateUrl }}" target="_blank" rel="noopener"
                        class="pos-add-customer" title="Create customer">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:18px;height:18px">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                    </a>
                </div>

                <div class="pos-disc-bar">
                    <span>Line discount:</span>
                    <button type="button" class="pos-mode-btn {{ $this->posDiscountMode === 'percent' ? 'active' : '' }}"
                        wire:click="$set('posDiscountMode', 'percent')">Percent</button>
                    <button type="button" class="pos-mode-btn {{ $this->posDiscountMode === 'amount' ? 'active' : '' }}"
                        wire:click="$set('posDiscountMode', 'amount')">Amount (PKR)</button>
                </div>

                <div class="pos-cart-header">
                    <span>Product</span>
                    <span style="text-align:center">Qty</span>
                    <span style="text-align:right">Total</span>
                    <span></span>
                </div>

                <div class="pos-cart-body">
                    @if (count($this->posCart) === 0)
                        <div class="pos-empty">
                            <p>No line items yet.</p>
                            <p style="margin-top:4px;font-size:11px">Select a product, variant, and branch to add to the cart.</p>
                        </div>
                    @else
                        @foreach ($this->posCart as $key => $item)
                            <div class="pos-cart-item" wire:key="pos-item-{{ $key }}">
                                <div>
                                    <div class="pos-cart-item-name">{{ $item['product_name'] }}</div>
                                    <div class="pos-cart-item-meta">{{ $item['variant_name'] }} · {{ $item['branch_name'] }}</div>
                                </div>
                                <div class="pos-qty-ctrl">
                                    <button type="button" class="pos-qty-btn" wire:click="posUpdateQty('{{ $key }}', -1)">−</button>
                                    <span class="pos-qty-val">{{ $item['quantity'] }}</span>
                                    <button type="button" class="pos-qty-btn" wire:click="posUpdateQty('{{ $key }}', 1)">+</button>
                                </div>
                                <div class="pos-item-price">PKR {{ number_format($item['line_total'], 2) }}</div>
                                <button type="button" class="pos-del-btn" wire:click="posRemoveItem('{{ $key }}')" title="Remove">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                                <div class="pos-item-inputs">
                                    @if ($this->posDiscountMode === 'percent')
                                        <div class="pos-field">
                                            <label>Disc %</label>
                                            <input type="number" min="0" max="100" step="0.01"
                                                value="{{ $item['discount'] }}"
                                                wire:change="posUpdateField('{{ $key }}', 'discount', $event.target.value)" />
                                        </div>
                                        <div class="pos-field">
                                            <label>Tax %</label>
                                            <input type="number" min="0" max="100" step="0.01"
                                                value="{{ $item['tax'] }}"
                                                wire:change="posUpdateField('{{ $key }}', 'tax', $event.target.value)" />
                                        </div>
                                    @else
                                        <div class="pos-field">
                                            <label>Disc PKR</label>
                                            <input type="number" min="0" step="0.01"
                                                value="{{ $item['discount_amount'] }}"
                                                wire:change="posUpdateField('{{ $key }}', 'discount_amount', $event.target.value)" />
                                        </div>
                                        <div class="pos-field">
                                            <label>Tax PKR</label>
                                            <input type="number" min="0" step="0.01"
                                                value="{{ $item['tax_amount'] }}"
                                                wire:change="posUpdateField('{{ $key }}', 'tax_amount', $event.target.value)" />
                                        </div>
                                    @endif
                                    <div class="pos-field">
                                        <label>Unit price</label>
                                        <input type="number" min="0" step="0.01"
                                            value="{{ $item['unit_price'] }}"
                                            wire:change="posUpdateField('{{ $key }}', 'unit_price', $event.target.value)" />
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>

                @error('pos')
                    <div class="pos-error">{{ $message }}</div>
                @enderror

                <div class="pos-summary">
                    <div class="pos-sum-row"><span>Subtotal</span><span>PKR {{ number_format($this->getPosSubtotal(), 2) }}</span></div>
                    <div class="pos-sum-row"><span>Discount</span><span>PKR {{ number_format($this->getPosDiscount(), 2) }}</span></div>
                    <div class="pos-sum-row"><span>Tax</span><span>PKR {{ number_format($this->getPosTax(), 2) }}</span></div>
                    <div class="pos-sum-row total"><span>Total</span><span>PKR {{ number_format($this->getPosTotal(), 2) }}</span></div>
                    @if ($this->getPosDueAmount() > 0)
                        <div class="pos-sum-row due"><span>Amount due</span><span>PKR {{ number_format($this->getPosDueAmount(), 2) }}</span></div>
                    @endif
                </div>

                <div class="pos-payment">
                    <h3>Payment</h3>
                    <div class="pos-pay-methods">
                        <button type="button"
                            class="pos-pay-method {{ $this->posPaymentMethod === 'cash' ? 'active' : '' }}"
                            wire:click="posSelectPaymentMethod('cash')">Cash / full payment</button>
                        <button type="button"
                            class="pos-pay-method {{ $this->posPaymentMethod === 'credit' ? 'active' : '' }}"
                            wire:click="posSelectPaymentMethod('credit')">Credit / partial</button>
                    </div>

                    <div class="pos-field" style="margin-bottom:8px">
                        <label>Current payment (PKR)</label>
                        @if ($this->posPaymentMethod === 'cash' && $this->getPosTotal() > 0)
                            <input type="text" readonly
                                value="{{ number_format((float) $this->posPaidAmount, 2, '.', '') }}" />
                        @else
                            <input type="number" min="0" step="0.01" max="{{ $this->getPosTotal() }}"
                                value="{{ $this->posPaidAmount }}"
                                wire:change="posPaidAmountChanged($event.target.value)" />
                        @endif
                    </div>

                    <div class="pos-quick-pay">
                        <button type="button" wire:click="posSetFullPayment">Full payment</button>
                        <button type="button" wire:click="posSetNoPayment">No payment</button>
                    </div>

                    @if ($this->getPosDueAmount() > 0 || $this->posPaymentMethod === 'credit')
                        <div class="pos-field">
                            <label>Payment due date</label>
                            <input type="date" wire:model.live="posDueDate" />
                        </div>
                    @endif
                </div>

                <div class="pos-notes">
                    <div class="pos-field">
                        <label>Notes</label>
                        <textarea wire:model="posNotes" rows="2" maxlength="255" placeholder="Optional sale notes…"></textarea>
                    </div>
                </div>

                <div class="pos-actions">
                    <button type="button" class="pos-cancel-btn" wire:click="posClearCart">Clear cart</button>
                    <button type="button" class="pos-place-btn"
                        wire:click="posSubmit"
                        wire:loading.attr="disabled"
                        @disabled(count($this->posCart) === 0 || ! $this->posCustomerId)>
                        <span wire:loading.remove wire:target="posSubmit">Place order</span>
                        <span wire:loading wire:target="posSubmit">Saving…</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Variant / branch modal (must stay inside x-data scope — no x-teleport) --}}
        <template x-if="showModal">
            <div class="pos-modal-overlay" @click.self="showModal = false" @keydown.escape.window="showModal = false">
                <div class="pos-modal" role="dialog" aria-modal="true" @click.stop>
                    <h3 x-text="modalProduct ? 'Add: ' + modalProduct.name : 'Add product'"></h3>

                    <label for="pos-modal-branch">Branch</label>
                    <select id="pos-modal-branch" x-model="modalBranch" @change="onBranchChanged()">
                        <option value="">Select branch…</option>
                        <template x-for="b in branches" :key="b.id">
                            <option :value="b.id" x-text="b.name"></option>
                        </template>
                    </select>

                    <label for="pos-modal-variant">Variant</label>
                    <select id="pos-modal-variant" x-model="modalVariant" :disabled="!modalBranch">
                        <option value="">Select variant…</option>
                        <template x-for="v in variants" :key="v.id">
                            <option
                                :value="v.id"
                                :disabled="!v.in_stock"
                                x-text="(v.name || v.sku || v.id)
                                    + ' — PKR ' + parseFloat(v.selling_price || 0).toFixed(2)
                                    + (v.tracks_inventory ? (v.in_stock ? ' (Stock: ' + parseFloat(v.stock || 0) + ')' : ' — Out of stock') : '')"
                            ></option>
                        </template>
                    </select>

                    <p x-show="modalBranch && variants.length > 0 && variants.every(v => !v.in_stock)"
                        style="margin:12px 0 0;font-size:12px;color:var(--pos-danger);">
                        No variants in stock for the selected branch.
                    </p>

                    <p x-show="!modalBranch"
                        style="margin:12px 0 0;font-size:12px;color:#6b7280;">
                        Select a branch to load variant stock.
                    </p>

                    <div class="pos-modal-actions">
                        <button type="button" class="pos-modal-cancel" @click="showModal = false">Cancel</button>
                        <button type="button" class="pos-modal-add" @click="confirmAdd()"
                            :disabled="!modalVariant || !modalBranch || !selectedVariantInStock()"
                            :style="(!modalVariant || !modalBranch || !selectedVariantInStock()) ? 'opacity:0.5;cursor:not-allowed' : ''">
                            Add to cart
                        </button>
                    </div>
                </div>
            </div>
        </template>

        {{-- Success modal --}}
        <template x-if="showOrderModal">
            <div class="pos-modal-overlay">
                <div class="pos-order-modal" role="dialog" aria-modal="true" @click.stop>
                    <div class="success-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="var(--pos-success)" style="width:28px;height:28px">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </div>
                    <h3>Order placed</h3>
                    <p class="sale-no" x-text="'Sale ' + (lastSaleNo ?? '') + ' was created successfully.'"></p>
                    <div class="pos-order-modal-btns">
                        <button type="button" class="pos-order-btn-invoice" @click="openInvoice()">View invoice</button>
                        <button type="button" class="pos-order-btn-sales" @click="goToSales()">All sales</button>
                    </div>
                    <button type="button" class="pos-order-btn-another" @click="showOrderModal = false; loadProducts()">
                        Continue selling
                    </button>
                </div>
            </div>
        </template>
    </div>
</x-filament-panels::page>
