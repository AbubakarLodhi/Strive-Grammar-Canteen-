<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Enums\AttachmentMetaType;
use App\Enums\AttachmentType;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\CreditReminderScheduler;
use App\Services\Finance\OperationalLedgerPoster;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\PaymentLedgerService;
use App\Support\ProductStockAvailability;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateSale extends CreateRecord
{
    protected static string $resource = SaleResource::class;

    // ─── POS toggle ───────────────────────────────────────────────
    public string $viewMode = 'standard'; // 'standard' | 'pos'

    // POS state
    public array $posCart = [];

    public ?string $posCustomerId = null;

    public string $posDiscountMode = 'percent';

    public string $posPaymentMethod = 'cash';

    /** @var float|int|string|null */
    public $posPaidAmount = 0;

    public string $posSaleNo = '';

    public string $posSaleDate = '';

    public string $posNotes = '';

    public string $posDueDate = '';

    public function mount(): void
    {
        parent::mount();
        $this->posSaleNo = 'SAL-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -6));
        $this->posSaleDate = now()->format('Y-m-d');

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

    // ─── POS helpers ──────────────────────────────────────────────
    public function getPosProducts(?string $search = null): array
    {
        $user = Filament::auth()->user();
        $merchantId = $user instanceof Merchant
            ? $user->id
            : $user?->merchant_id;

        $query = Product::query()
            ->withoutTrashed()
            ->where('is_active', true)
            ->where('merchant_id', $merchantId);

        if (filled($search)) {
            $term = '%'.mb_strtolower(trim($search)).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(sku) LIKE ?', [$term])
            );
        }

        return $query->limit(50)->get(['id', 'name', 'sku'])->toArray();
    }

    public function getPosVariants(string $productId): array
    {
        return ProductVariant::query()
            ->withoutTrashed()
            ->where('product_id', $productId)
            ->limit(50)
            ->get(['id', 'name', 'sku', 'selling_price'])
            ->toArray();
    }

    public function getPosBranches(string $productId): array
    {
        $user = Filament::auth()->user();
        $merchantId = $user instanceof Merchant
            ? $user->id
            : $user?->merchant_id;

        $hasBranchAssignments = DB::table('branch_products')
            ->where('product_id', $productId)
            ->exists();

        $query = Branch::query()
            ->withoutTrashed()
            ->where('merchant_id', $merchantId);

        if ($hasBranchAssignments) {
            $query->whereExists(fn ($q) => $q->selectRaw(1)
                ->from('branch_products')
                ->whereColumn('branch_products.branch_id', 'branches.id')
                ->where('branch_products.product_id', $productId)
            );
        }

        return $query->orderBy('name')->get(['id', 'name'])->toArray();
    }

    /** @return array<int, array{id: string, name: string}> */
    public function getPosCustomers(): array
    {
        $user = Filament::auth()->user();

        return CustomerResource::scopeVisibleCustomers(
            Customer::query(),
            $user,
        )
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name'])
            ->map(fn (Customer $c) => ['id' => (string) $c->id, 'name' => $c->name])
            ->all();
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
        $lineSubtotal = $qty * $unit;

        if ($this->posDiscountMode === 'amount') {
            $discAmt = min(max(0, (float) ($item['discount_amount'] ?? 0)), $lineSubtotal);
            $taxable = max(0, $lineSubtotal - $discAmt);
            $taxAmt = min(max(0, (float) ($item['tax_amount'] ?? 0)), $lineSubtotal);
            $discRate = $lineSubtotal > 0 ? ($discAmt / $lineSubtotal) * 100 : 0;
            $taxRate = $taxable > 0 ? ($taxAmt / $taxable) * 100 : 0;
            $item['discount'] = round($discRate, 6);
            $item['tax'] = round($taxRate, 6);
            $item['discount_amount'] = round($discAmt, 2);
            $item['tax_amount'] = round($taxAmt, 2);
        } else {
            $discRate = max(0, min(100, (float) ($item['discount'] ?? 0)));
            $taxRate = max(0, min(100, (float) ($item['tax'] ?? 0)));
            $discAmt = $lineSubtotal * ($discRate / 100);
            $taxable = max(0, $lineSubtotal - $discAmt);
            $taxAmt = $taxable * ($taxRate / 100);
            $item['discount_amount'] = round($discAmt, 2);
            $item['tax_amount'] = round($taxAmt, 2);
        }

        $item['line_total'] = round($taxable + $taxAmt, 2);
    }

    private function recalcPosCart(): void
    {
        foreach (array_keys($this->posCart) as $key) {
            $this->recalcPosItem($key);
        }
        $this->syncPosPayment();
    }

    private function syncPosPayment(): void
    {
        $total = $this->getPosTotal();

        if ($this->posPaymentMethod === 'cash') {
            $this->posPaidAmount = $total;

            return;
        }

        $this->posPaidAmount = max(0, min($total, (float) ($this->posPaidAmount ?? 0)));

        if ($this->getPosDueAmount() > 0 && blank($this->posDueDate)) {
            $this->posDueDate = Carbon::parse($this->posSaleDate ?: now())
                ->addDays(30)
                ->format('Y-m-d');
        }
    }

    public function updatedPosDiscountMode(): void
    {
        $this->recalcPosCart();
    }

    public function posSelectPaymentMethod(string $method): void
    {
        $this->posPaymentMethod = $method === 'credit' ? 'credit' : 'cash';

        if ($this->posPaymentMethod === 'cash') {
            $this->posPaidAmount = $this->getPosTotal();
            $this->posDueDate = '';

            return;
        }

        if ((float) $this->posPaidAmount >= $this->getPosTotal()) {
            $this->posPaidAmount = 0;
        }

        $this->ensurePosDueDate();
    }

    public function posPaidAmountChanged($value): void
    {
        $total = $this->getPosTotal();
        $paid = max(0, min($total, (float) $value));

        $this->posPaidAmount = $paid;

        if ($paid >= $total - 0.001) {
            $this->posPaymentMethod = 'cash';
            $this->posDueDate = '';

            return;
        }

        $this->posPaymentMethod = 'credit';
        $this->ensurePosDueDate();
    }

    private function ensurePosDueDate(): void
    {
        if ($this->getPosDueAmount() > 0 && blank($this->posDueDate)) {
            $this->posDueDate = Carbon::parse($this->posSaleDate ?: now())
                ->addDays(30)
                ->format('Y-m-d');
        }
    }

    public function posSetFullPayment(): void
    {
        $this->posSelectPaymentMethod('cash');
    }

    public function posSetNoPayment(): void
    {
        $this->posPaymentMethod = 'credit';
        $this->posPaidAmount = 0;
        $this->ensurePosDueDate();
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

    public function getPosDueAmount(): float
    {
        return max(0, round($this->getPosTotal() - (float) $this->posPaidAmount, 2));
    }

    // ─── POS submit ───────────────────────────────────────────────
    public function posSubmit(): void
    {
        if (! $this->validatePosOrder()) {
            return;
        }

        $sale = $this->handleRecordCreation($this->buildPosSaleData());
        $this->queueSaleCreatedEmail($sale->fresh(['customer', 'merchant']));
        $this->resetPosAfterSale();

        $this->dispatch('pos-order-placed', saleId: $sale->id, saleNo: $sale->sale_no);
        $this->dispatch('pos-products-refresh');
    }

    // ─── POS submit and create another ────────────────────────────
    public function posSubmitAndCreateAnother(): void
    {
        if (! $this->validatePosOrder()) {
            return;
        }

        $sale = $this->handleRecordCreation($this->buildPosSaleData());
        $this->queueSaleCreatedEmail($sale->fresh(['customer', 'merchant']));
        $this->resetPosAfterSale(keepView: true);

        Notification::make()
            ->title('Sale created')
            ->body('Cart cleared — ready for the next order.')
            ->success()
            ->send();

        $this->dispatch('pos-products-refresh');
    }

    private function validatePosOrder(): bool
    {
        if (empty($this->posCart) || ! $this->posCustomerId) {
            $this->addError('pos', 'Select a customer and add at least one product.');

            return false;
        }

        if ($this->getPosDueAmount() > 0 && blank($this->posDueDate)) {
            $this->addError('pos', 'Set a payment due date for credit or partial payments.');

            return false;
        }

        $stockError = ProductStockAvailability::validateSaleItemsStock(array_values($this->posCart));

        if ($stockError !== null) {
            Notification::make()
                ->title('Out of stock')
                ->body($stockError)
                ->warning()
                ->send();

            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function buildPosSaleData(): array
    {
        $total = $this->getPosTotal();
        $paid = max(0, min($total, (float) $this->posPaidAmount));
        $due = max(0, round($total - $paid, 2));

        return [
            'sale_no' => $this->posSaleNo,
            'sale_date' => $this->posSaleDate,
            'customer_id' => $this->posCustomerId,
            'payment_method' => $due > 0 ? 'credit' : 'cash',
            'paid_amount' => $paid,
            'due_date' => $due > 0 && filled($this->posDueDate) ? $this->posDueDate : null,
            'notes' => filled($this->posNotes) ? $this->posNotes : null,
            'items' => array_values($this->posCart),
        ];
    }

    private function resetPosAfterSale(bool $keepView = false): void
    {
        $this->posCart = [];
        $this->posCustomerId = null;
        $this->posPaidAmount = 0.0;
        $this->posPaymentMethod = 'cash';
        $this->posNotes = '';
        $this->posDueDate = '';
        $this->posDiscountMode = 'percent';
        $this->posSaleNo = 'SAL-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -6));
        $this->posSaleDate = now()->format('Y-m-d');
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return $this->viewMode === 'pos' ? ['fi-pos-sales-page'] : [];
    }

    public function getBreadcrumbs(): array
    {
        if ($this->viewMode === 'pos') {
            return [];
        }

        return parent::getBreadcrumbs();
    }

    // ─── Header actions (toggle buttons) ─────────────────────────
    protected function getHeaderActions(): array
    {
        return [
            Action::make('switchToPos')
                ->label('POS view')
                ->icon('heroicon-o-shopping-cart')
                ->color('success')
                ->visible(fn () => $this->viewMode === 'standard')
                ->action(fn () => $this->switchToPos()),

            Action::make('switchToStandard')
                ->label('Standard view')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn () => $this->viewMode === 'pos')
                ->action(fn () => $this->switchToStandard()),
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

    // ─── Redirect ─────────────────────────────────────────────────
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    // ─── handleRecordCreation ─────────────────────────────────────
    protected function handleRecordCreation(array $data): Model
    {
        $stockError = ProductStockAvailability::validateSaleItemsStock($data['items'] ?? []);

        if ($stockError !== null) {
            throw ValidationException::withMessages([
                'data.items' => $stockError,
            ]);
        }

        return DB::transaction(function () use ($data) {

            $items = $data['items'] ?? [];
            unset($data['items']);
            $items = self::normalizeItems($items);
            $paymentDate = $data['payment_date'] ?? null;
            unset($data['payment_date']);

            $panel = Filament::getCurrentPanel();
            $guard = $panel?->getAuthGuard();
            $user = $guard ? auth($guard)->user() : Filament::auth()->user();

            if ($guard === 'staff' && $user instanceof User) {
                $data['merchant_id'] = $user->merchant_id;
                $data['created_by'] = $user->id;
            } elseif ($user instanceof Merchant) {
                $data['merchant_id'] = $user->id;
                $data['created_by'] = null;
            } elseif ($user instanceof User) {
                $data['merchant_id'] = $user->merchant_id;
                $data['created_by'] = $user->id;
            }

            $subtotal = 0.0;
            $totalDiscount = 0.0;
            $totalTax = 0.0;

            foreach ($items as $item) {
                $qty = (float) ($item['quantity'] ?? 0);
                $unitPrice = (float) ($item['unit_price'] ?? 0);
                $lineSubtotal = $qty * $unitPrice;
                $subtotal += $lineSubtotal;

                $discountRate = max(0, min(100, (float) ($item['discount'] ?? 0)));
                $taxRate = max(0, min(100, (float) ($item['tax'] ?? 0)));

                $discountAmount = $lineSubtotal * ($discountRate / 100);
                $taxableAmount = max(0, $lineSubtotal - $discountAmount);
                $taxAmount = $taxableAmount * ($taxRate / 100);

                $totalDiscount += $discountAmount;
                $totalTax += $taxAmount;
            }

            $data['subtotal'] = round($subtotal, 2);
            $data['total_amount'] = round($subtotal - $totalDiscount + $totalTax, 2);

            self::applyPaymentFields($data);

            $sale = static::getModel()::create($data);

            if ((float) ($data['paid_amount'] ?? 0) > 0) {
                PaymentLedgerService::recordSalePayment(
                    $sale,
                    (float) $data['paid_amount'],
                    $paymentDate ?? $data['sale_date'] ?? null
                );
            }

            foreach ($items as $item) {
                $branch = Branch::select('id', 'business_id')->find($item['branch_id']);
                if (! $branch) {
                    continue;
                }

                $saleItem = $sale->items()->create([
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

            $firstItem = $sale->items()->first();
            if ($firstItem) {
                Order::create([
                    'merchant_id' => $sale->merchant_id,
                    'sale_id' => $sale->id,
                    'status' => 'pending',
                ]);
            }

            $state = method_exists($this, 'form') ? $this->form->getRawState() : [];

            if (array_key_exists('merchant_logo', $state)) {
                $merchant = $user instanceof Merchant ? $user : $user?->merchant;
                if ($merchant && ($logo = collect($state['merchant_logo'])->first())) {
                    $merchant->logo()?->delete();
                    $merchant->logo()->create([
                        'merchant_id' => $merchant->id,
                        'type' => AttachmentType::IMAGE,
                        'meta_type' => AttachmentMetaType::MERCHANT_LOGO,
                        'photo_url' => $logo,
                    ]);
                }
            }

            $sale = $sale->fresh();
            $saleId = $sale->id;

            DB::afterCommit(function () use ($saleId): void {
                $fresh = Sale::query()->find($saleId);

                if (! $fresh) {
                    return;
                }

                $scheduler = app(CreditReminderScheduler::class);

                if ($fresh->isCreditWithBalance()) {
                    $scheduler->syncSaleReminders($fresh);
                } else {
                    $scheduler->deactivateSaleReminders($fresh);
                }
            });

            return $sale;
        });
    }

    protected function afterCreate(): void
    {
        $sale = $this->record->fresh(['customer']);
        if (! $sale) {
            return;
        }
        $this->queueSaleCreatedEmail($sale);
        app(OperationalLedgerPoster::class)->syncSale($sale);
    }

    private function queueSaleCreatedEmail(Sale $sale): void
    {
        try {
            app(NotificationDispatcher::class)->dispatchSaleCreated($sale->fresh(['customer', 'merchant']));
        } catch (Throwable $exception) {
            Log::error('SaleCreated notification failed.', [
                'sale_id' => $sale->id,
                'error' => $exception->getMessage(),
            ]);
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
        $dueAmount = max(0, round($totalAmount - $paidAmount, 2));

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
}
