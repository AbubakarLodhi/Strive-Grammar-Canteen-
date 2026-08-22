<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Enums\AttachmentMetaType;
use App\Enums\AttachmentType;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\Branch;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Finance\OperationalLedgerPoster;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\PaymentLedgerService;
use App\Support\ProductStockAvailability;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EditSale extends EditRecord
{
    protected static string $resource = SaleResource::class;

    protected bool $isPartiallyReturned = false;

    protected float $paymentDelta = 0.0;

    protected ?string $paymentDate = null;

    protected string $paymentEntryType = 'payment';

    // ─── POS toggle ───────────────────────────────────────────────
    public string $viewMode = 'standard'; // 'standard' | 'pos'

    // POS state
    public array $posCart = [];

    public ?string $posCustomerId = null;

    public string $posDiscountMode = 'percent';

    public string $posPaymentMethod = 'cash';

    public string $posSaleNo = '';

    public string $posSaleDate = '';

    public function mount($record): void
    {
        parent::mount($record);
        $this->posSaleNo = $this->record->sale_no ?? ('SAL-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -6)));
        $this->posSaleDate = $this->record->sale_date ?? now()->format('Y-m-d');
        $this->posCustomerId = $this->record->customer_id;

        // Pre-load existing cart items from the sale
        foreach ($this->record->items as $item) {
            $variant = $item->variants->first();
            $key = $item->product_id.'_'.($variant?->product_variant_id ?? 'no-variant').'_'.$item->branch_id;
            $this->posCart[$key] = [
                'key' => $key,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name ?? '',
                'product_variant_id' => $variant?->product_variant_id ?? null,
                'variant_name' => $variant?->productVariant?->name ?? '',
                'branch_id' => $item->branch_id,
                'branch_name' => $item->branch?->name ?? '',
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'discount' => (float) ($item->discount ?? 0),
                'discount_amount' => 0,
                'tax' => (float) ($item->tax ?? 0),
                'tax_amount' => 0,
                'line_total' => (float) $item->line_total,
            ];
        }
        $this->recalcPosCart();
    }

    // ─── View toggle ──────────────────────────────────────────────
    public function switchToPos(): void
    {
        $this->viewMode = 'pos';
    }

    public function switchToStandard(): void
    {
        $this->viewMode = 'standard';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchPosProducts(?string $search = null, ?string $categoryId = null): array
    {
        $merchantId = $this->resolveMerchantId();

        if (! filled($merchantId)) {
            return [];
        }

        return ProductStockAvailability::posProductsForMerchant(
            (string) $merchantId,
            $search,
            filled($categoryId) ? $categoryId : null,
            inStockOnly: false,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchPosVariants(string $productId, ?string $branchId = null): array
    {
        if (! filled($productId)) {
            return [];
        }

        return ProductStockAvailability::posVariantsForProduct(
            $productId,
            filled($branchId) ? $branchId : null,
            inStockOnly: false,
        );
    }

    private function resolveMerchantId(): ?string
    {
        $user = Filament::auth()->user();

        if ($user instanceof Merchant) {
            return (string) $user->id;
        }

        return filled($user?->merchant_id) ? (string) $user->merchant_id : null;
    }

    // ─── POS helpers ──────────────────────────────────────────────
    public function posAddItem(string $productId, string $variantId, string $branchId): void
    {
        $variant = ProductVariant::query()->with('product')->find($variantId);
        if (! $variant) {
            return;
        }

        $key = $productId.'_'.$variantId.'_'.$branchId;
        $newQty = isset($this->posCart[$key])
            ? (int) $this->posCart[$key]['quantity'] + 1
            : 1;

        if (! ProductStockAvailability::isVariantAvailable($variant, $branchId, $newQty)) {
            Notification::make()
                ->title('Out of stock')
                ->body('This product is not available in the selected branch.')
                ->warning()
                ->send();

            return;
        }

        if (isset($this->posCart[$key])) {
            $this->posCart[$key]['quantity']++;
        } else {
            $product = Product::find($productId);
            $branch = Branch::find($branchId);
            $this->posCart[$key] = [
                'key' => $key,
                'product_id' => $productId,
                'product_name' => $product?->name ?? '',
                'product_variant_id' => $variantId,
                'variant_name' => $variant->name ?? $variant->sku ?? '',
                'branch_id' => $branchId,
                'branch_name' => $branch?->name ?? '',
                'quantity' => 1,
                'unit_price' => (float) ($variant->selling_price ?? 0),
                'discount' => 0,
                'discount_amount' => 0,
                'tax' => 0,
                'tax_amount' => 0,
                'line_total' => (float) ($variant->selling_price ?? 0),
            ];
        }
        $this->recalcPosCart();
    }

    public function posUpdateQty(string $key, int $delta): void
    {
        if (! isset($this->posCart[$key])) {
            return;
        }

        $newQty = $this->posCart[$key]['quantity'] + $delta;

        if ($newQty <= 0) {
            unset($this->posCart[$key]);
            $this->recalcPosCart();

            return;
        }

        if ($delta > 0) {
            $variant = ProductVariant::query()
                ->with('product')
                ->find($this->posCart[$key]['product_variant_id'] ?? null);

            if (
                $variant
                && ! ProductStockAvailability::isVariantAvailable(
                    $variant,
                    (string) ($this->posCart[$key]['branch_id'] ?? ''),
                    $newQty,
                )
            ) {
                Notification::make()
                    ->title('Out of stock')
                    ->body('Not enough stock remaining for this item.')
                    ->warning()
                    ->send();

                return;
            }
        }

        $this->posCart[$key]['quantity'] = $newQty;
        $this->recalcPosItem($key);
        $this->recalcPosCart();
    }

    public function posRemoveItem(string $key): void
    {
        unset($this->posCart[$key]);
        // Do NOT call array_values() — it converts string keys to numeric indices,
        // which breaks all subsequent posUpdateQty / posRemoveItem calls.
        $this->recalcPosCart();
    }

    public function posClearCart(): void
    {
        $this->posCart = [];
    }

    public function posUpdateField(string $key, string $field, $value): void
    {
        if (! isset($this->posCart[$key])) {
            return;
        }
        $this->posCart[$key][$field] = $value;
        $this->recalcPosItem($key);
        $this->recalcPosCart();
    }

    private function recalcPosItem(string $key): void
    {
        $item = &$this->posCart[$key];
        $qty = (float) ($item['quantity'] ?? 1);
        $unit = (float) ($item['unit_price'] ?? 0);
        $subtotal = $qty * $unit;
        $discRate = (float) ($item['discount'] ?? 0);
        $taxRate = (float) ($item['tax'] ?? 0);

        $discAmt = $subtotal * ($discRate / 100);
        $taxable = max(0, $subtotal - $discAmt);
        $taxAmt = $taxable * ($taxRate / 100);

        $item['discount_amount'] = round($discAmt, 2);
        $item['tax_amount'] = round($taxAmt, 2);
        $item['line_total'] = round($taxable + $taxAmt, 2);
    }

    private function recalcPosCart(): void
    {
        foreach (array_keys($this->posCart) as $key) {
            $this->recalcPosItem($key);
        }
    }

    public function getPosSubtotal(): float
    {
        return collect($this->posCart)->sum(fn ($i) => (float) ($i['quantity'] ?? 0) * (float) ($i['unit_price'] ?? 0));
    }

    public function getPosDiscount(): float
    {
        return collect($this->posCart)->sum(fn ($i) => (float) ($i['discount_amount'] ?? 0));
    }

    public function getPosTax(): float
    {
        return collect($this->posCart)->sum(fn ($i) => (float) ($i['tax_amount'] ?? 0));
    }

    public function getPosTotal(): float
    {
        return $this->getPosSubtotal() - $this->getPosDiscount() + $this->getPosTax();
    }

    // ─── POS submit (update existing sale) ────────────────────────
    public function posSubmit(): void
    {
        if (empty($this->posCart) || ! $this->posCustomerId) {
            $this->addError('pos', 'Please select a customer and add at least one item.');

            return;
        }

        $items = array_values($this->posCart);

        DB::transaction(function () use ($items) {
            $subtotal = collect($items)->sum(fn ($i) => (float) ($i['quantity'] ?? 0) * (float) ($i['unit_price'] ?? 0));
            $totalDiscount = collect($items)->sum(fn ($i) => (float) ($i['discount_amount'] ?? 0));
            $totalTax = collect($items)->sum(fn ($i) => (float) ($i['tax_amount'] ?? 0));
            $totalAmount = $subtotal - $totalDiscount + $totalTax;
            $paidAmount = $this->posPaymentMethod === 'cash' ? $totalAmount : 0;
            $dueAmount = $totalAmount - $paidAmount;

            $this->record->update([
                'customer_id' => $this->posCustomerId,
                'sale_date' => $this->posSaleDate,
                'subtotal' => $subtotal,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
            ]);

            // Recreate items
            $this->record->items()->delete();

            foreach ($items as $item) {
                $branch = Branch::select('id', 'business_id')->find($item['branch_id']);
                if (! $branch) {
                    continue;
                }

                $saleItem = $this->record->items()->create([
                    'business_id' => $branch->business_id,
                    'branch_id' => $branch->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['line_total'],
                    'discount' => $item['discount'] ?? 0,
                    'tax' => $item['tax'] ?? 0,
                ]);

                if (! empty($item['product_variant_id'])) {
                    $saleItem->variants()->create([
                        'product_variant_id' => $item['product_variant_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'line_total' => $item['line_total'],
                    ]);
                }
            }

            if ($paidAmount > 0) {
                PaymentLedgerService::recordSalePayment(
                    $this->record->fresh(),
                    $paidAmount,
                    $this->posSaleDate,
                );
            }
        });

        Notification::make()
            ->title('Sale updated successfully')
            ->success()
            ->send();

        $this->redirect($this->getResource()::getUrl('index'));
    }

    // ─── Header actions ───────────────────────────────────────────
    protected function getHeaderActions(): array
    {
        $guard = Filament::getCurrentPanel()->getAuthGuard();

        return [
            Action::make('switchToPos')
                ->label('POS View')
                ->icon('heroicon-o-shopping-cart')
                ->color('success')
                ->visible(fn () => $this->viewMode === 'standard')
                ->action(fn () => $this->switchToPos()),

            Action::make('switchToStandard')
                ->label('Standard View')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn () => $this->viewMode === 'pos')
                ->action(fn () => $this->switchToStandard()),

            ViewAction::make()
                ->visible(fn () => auth($guard)->user()?->hasPermissionTo('sales.view', $guard)),

            DeleteAction::make()
                ->color('danger')
                ->visible(fn () => auth($guard)->user()?->hasPermissionTo('sales.delete', $guard)),
        ];
    }

    // ─── Render ───────────────────────────────────────────────────
    public function getView(): string
    {
        if ($this->viewMode === 'pos') {
            return 'filament.resources.sales.pages.pos-sale';
        }

        return parent::getView();
    }

    public function getTitle(): string
    {
        return 'Edit Sale';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->isPartiallyReturned = $this->isPartiallyReturnedSale();
        $recordedPaid = $this->getRecordedPaidAmount();

        $data['items'] = $this->record->items->map(fn ($item) => [
            'line_subtotal' => (float) $item->line_total,
            'discount_amount' => (float) $item->line_total * ((float) ($item->discount ?? 0) / 100),
            'tax_amount' => ((float) $item->line_total - ((float) $item->line_total * ((float) ($item->discount ?? 0) / 100)))
                * ((float) ($item->tax ?? 0) / 100),
            'branch_id' => $item->branch_id,
            'product_id' => $item->product_id,
            'product_variant_id' => optional($item->variants->first())->product_variant_id,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'line_total' => $item->line_total,
            'discount' => $item->discount ?? 0,
            'tax' => $item->tax ?? 0,
        ])->toArray();

        $data['previous_paid_amount'] = $recordedPaid;
        $data['current_payment_amount'] = 0;
        $data['paid_amount'] = $recordedPaid;

        if (! array_key_exists('due_amount', $data) || $data['due_amount'] === null) {
            $totalAmount = (float) ($data['total_amount'] ?? 0);
            $data['due_amount'] = round(max(0, $totalAmount - $recordedPaid), 2);
        }

        $data['payment_date'] = now()->toDateString();
        $data['is_partial_return'] = $this->isPartiallyReturned;
        $data['payment_method'] = ((float) ($data['due_amount'] ?? 0)) > 0 ? 'credit' : 'cash';

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->isPartiallyReturnedSale()) {
            $this->isPartiallyReturned = true;
            $totalAmount = (float) ($this->record->total_amount ?? 0);
            $this->preparePaymentDelta($data, $totalAmount);
            $recordedPaid = $this->getRecordedPaidAmount();

            $lockedData = [
                'total_amount' => $totalAmount,
                'paid_amount' => $recordedPaid + $this->paymentDelta,
            ];

            self::applyPaymentFields($lockedData);

            return [
                'paid_amount' => $lockedData['paid_amount'],
                'due_amount' => $lockedData['due_amount'],
                'payment_type' => $lockedData['payment_type'],
            ];
        }

        $this->preparePaymentDelta($data);
        $items = $data['items'] ?? [];
        $currentPaymentAmount = (float) ($data['current_payment_amount'] ?? 0);

        if (! $this->isPartiallyReturned) {
            $stockError = ProductStockAvailability::validateSaleItemsStock(
                self::normalizeItems($items),
                (string) $this->record->id,
            );

            if ($stockError !== null) {
                throw ValidationException::withMessages([
                    'data.items' => $stockError,
                ]);
            }
        }

        unset($data['items'], $data['current_payment_amount'], $data['payment_date']);
        $items = self::normalizeItems($items);

        $subtotal = collect($items)->sum(fn ($i) => (float) ($i['line_total'] ?? 0));
        $totalDiscount = 0.0;
        $totalTax = 0.0;

        foreach ($items as $item) {
            $lineTotal = (float) ($item['line_total'] ?? 0);
            $discountRate = max(0, min(100, (float) ($item['discount'] ?? 0)));
            $taxRate = max(0, min(100, (float) ($item['tax'] ?? 0)));

            $discountAmount = $lineTotal * ($discountRate / 100);
            $taxableAmount = $lineTotal - $discountAmount;
            $taxAmount = $taxableAmount * ($taxRate / 100);

            $totalDiscount += $discountAmount;
            $totalTax += $taxAmount;
        }

        $data['subtotal'] = $subtotal;
        $data['total_amount'] = $subtotal - $totalDiscount + $totalTax;
        $data['current_payment_amount'] = $currentPaymentAmount;
        $this->preparePaymentDelta($data, (float) $data['total_amount']);
        unset($data['current_payment_amount']);
        $data['paid_amount'] = $this->getRecordedPaidAmount() + $this->paymentDelta;
        self::applyPaymentFields($data);

        return $data;
    }

    protected function afterSave(): void
    {
        $shouldNotifyCustomer = $this->paymentDelta != 0.0;

        if ($this->isPartiallyReturned && $this->paymentDelta == 0.0) {
            return;
        }

        DB::transaction(function () {
            $items = $this->form->getState()['items'] ?? [];
            $items = self::normalizeItems($items);

            if (! $this->isPartiallyReturned) {
                $this->record->items()->delete();

                foreach ($items as $item) {
                    $branch = Branch::select('id', 'business_id')->find($item['branch_id']);
                    if (! $branch) {
                        continue;
                    }

                    $saleItem = $this->record->items()->create([
                        'business_id' => $branch->business_id,
                        'branch_id' => $branch->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'line_total' => $item['line_total'],
                        'discount' => $item['discount'] ?? 0,
                        'tax' => $item['tax'] ?? 0,
                    ]);

                    if (! empty($item['product_variant_id'])) {
                        $saleItem->variants()->create([
                            'product_variant_id' => $item['product_variant_id'],
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['unit_price'],
                            'line_total' => $item['line_total'],
                        ]);
                    }
                }
            }

            $state = $this->form->getRawState();
            $user = Filament::auth()->user();

            if (array_key_exists('merchant_logo', $state)) {
                $merchant = $user instanceof Merchant ? $user : $user?->merchant;

                if ($merchant) {
                    $logo = collect((array) ($state['merchant_logo'] ?? []))->filter()->first();

                    if ($logo) {
                        $merchant->logo()?->delete();
                        $merchant->logo()->create([
                            'merchant_id' => $merchant->id,
                            'type' => AttachmentType::IMAGE,
                            'meta_type' => AttachmentMetaType::MERCHANT_LOGO,
                            'photo_url' => $logo,
                        ]);
                    }
                }
            }

            if ($this->paymentDelta != 0.0) {
                PaymentLedgerService::recordSalePayment(
                    $this->record->fresh(),
                    $this->paymentDelta,
                    $this->paymentDate,
                    $this->paymentEntryType,
                );
            }
        });

        if ($shouldNotifyCustomer) {
            $this->sendPaymentSyncEmail();
        }

        $sale = $this->record->fresh(['payments']);
        if ($sale) {
            app(OperationalLedgerPoster::class)->syncSale($sale);
        }

    }

    private static function normalizeItems(array $items): array
    {
        foreach ($items as &$item) {
            $qty = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $lineSubtotal = $qty * $unitPrice;
            $lineTotal = $lineSubtotal;

            $discountRate = (float) ($item['discount'] ?? 0);
            $discountAmount = (float) ($item['discount_amount'] ?? 0);

            if ($discountAmount > 0 && $lineTotal > 0) {
                $discountRate = ($discountAmount / $lineTotal) * 100;
            }

            $discountRate = max(0, min(100, $discountRate));
            $discountAmount = $lineTotal * ($discountRate / 100);

            $taxRate = (float) ($item['tax'] ?? 0);
            $taxAmount = (float) ($item['tax_amount'] ?? 0);
            $taxableAmount = $lineTotal - $discountAmount;

            if ($taxAmount > 0 && $taxableAmount > 0) {
                $taxRate = ($taxAmount / $taxableAmount) * 100;
            }

            $taxRate = max(0, min(100, $taxRate));

            $item['line_total'] = $lineTotal;
            $item['discount'] = round($discountRate, 6);
            $item['tax'] = round($taxRate, 6);
        }

        return $items;
    }

    private static function applyPaymentFields(array &$data): void
    {
        if (($data['payment_method'] ?? null) === null) {
            $data['payment_method'] = ((float) ($data['due_amount'] ?? 0)) > 0 ? 'credit' : 'cash';
        }

        $totalAmount = max(0, (float) ($data['total_amount'] ?? 0));
        $paidAmount = $data['paid_amount'] ?? null;
        $paymentMethod = $data['payment_method'] ?? null;

        if ($paymentMethod === 'credit') {
            $paidAmount = max(0, (float) ($paidAmount ?? 0));
        } else {
            $paidAmount = ($paidAmount === null || $paidAmount === '' || (float) $paidAmount === 0.0)
                ? $totalAmount
                : (float) $paidAmount;
        }

        $paidAmount = max(0, min($totalAmount, (float) $paidAmount));
        $dueAmount = max(0, $totalAmount - $paidAmount);

        $data['paid_amount'] = round($paidAmount, 2);
        $data['due_amount'] = round($dueAmount, 2);
        $data['payment_type'] = $dueAmount > 0 ? 'credit' : 'cash';

        if ($dueAmount <= 0) {
            $data['due_date'] = null;
        } elseif (empty($data['due_date']) && ! empty($data['sale_date'])) {
            $data['due_date'] = Carbon::parse($data['sale_date'])->addDays(30)->toDateString();
        }

        unset($data['payment_method']);
    }

    public function isPartiallyReturnedSale(): bool
    {
        if (! $this->record?->returns()->exists()) {
            return false;
        }

        foreach ($this->record->items as $item) {
            $itemQty = (int) ($item->quantity ?? 0);
            $variantQty = (int) $item->variants->sum('quantity');

            if (max($itemQty, $variantQty) > 0) {
                return true;
            }
        }

        return false;
    }

    protected function preparePaymentDelta(array $data, ?float $forcedTotalAmount = null): void
    {
        $recordedPaid = $this->getRecordedPaidAmount();
        $totalAmount = max(0, $forcedTotalAmount ?? (float) ($data['total_amount'] ?? $this->record->total_amount ?? 0));
        $currentPayment = max(0, (float) ($data['current_payment_amount'] ?? 0));
        $desiredPaid = max(0, min($totalAmount, $recordedPaid + $currentPayment));
        $delta = round($desiredPaid - $recordedPaid, 2);

        if ($desiredPaid < 0 || $desiredPaid > $totalAmount) {
            throw ValidationException::withMessages([
                'data.current_payment_amount' => 'Current payment cannot exceed the remaining amount.',
            ]);
        }

        $this->paymentDelta = $delta;
        $this->paymentEntryType = 'payment';
        $this->paymentDate = filled($data['payment_date'] ?? null)
            ? (string) $data['payment_date']
            : now()->toDateString();
    }

    private function getRecordedPaidAmount(): float
    {
        $this->record->refresh();

        return round(max(0, (float) ($this->record->paid_amount ?? 0)), 2);
    }

    public function confirmReversePayment(string $paymentId): void
    {
        $this->mountAction('reversePayment', ['paymentId' => $paymentId]);
    }

    public function reversePaymentAction(): Action
    {
        return Action::make('reversePayment')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete payment history entry?')
            ->modalDescription('This will remove this payment entry from payment history.')
            ->modalSubmitActionLabel('Delete')
            ->action(function (array $arguments): void {
                $paymentId = (string) ($arguments['paymentId'] ?? '');

                if ($paymentId === '') {
                    Notification::make()->danger()->title('Invalid payment selected for reversal.')->send();

                    return;
                }

                $this->reversePayment($paymentId);
            });
    }

    public function reversePayment(string $paymentId): void
    {
        $payment = $this->record->payments()->whereKey($paymentId)->first();

        if (! $payment instanceof Payment || (float) ($payment->amount ?? 0) <= 0 || $payment->entry_type !== 'payment') {
            Notification::make()->danger()->title('Invalid payment selected for reversal.')->send();

            return;
        }

        $payment->delete();
        PaymentLedgerService::syncSaleTotals($this->record->fresh());

        $this->record = $this->record->fresh(['items.variants', 'payments']);
        app(OperationalLedgerPoster::class)->syncSale($this->record);
        $this->form->fill($this->mutateFormDataBeforeFill($this->record->attributesToArray()));

        Notification::make()->success()->title('Payment deleted.')->send();
    }

    private function sendPaymentSyncEmail(): void
    {
        $sale = $this->record->fresh(['customer', 'merchant.settings', 'payments']);

        if (! $sale) {
            return;
        }

        try {
            app(NotificationDispatcher::class)->dispatchSaleCreated($sale);
        } catch (\Throwable $exception) {
            Log::warning('Failed to send sale payment sync notification.', [
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
