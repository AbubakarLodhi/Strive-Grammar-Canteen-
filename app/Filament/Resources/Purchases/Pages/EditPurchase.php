<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Branch;
use App\Models\Payment;
use App\Services\Finance\OperationalLedgerPoster;
use App\Services\PaymentLedgerService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EditPurchase extends EditRecord
{
    protected static string $resource = PurchaseResource::class;

    private const PAGINATED_ITEMS_THRESHOLD = 75;

    private const ITEMS_PER_PAGE = 25;

    protected bool $isPartiallyReturned = false;

    protected float $paymentDelta = 0.0;

    protected ?string $paymentDate = null;

    protected string $paymentEntryType = 'payment';

    protected bool $usesPaginatedItems = false;

    public int $itemsPage = 1;

    public function getTitle(): string
    {
        $name = (string) ($this->record?->name ?? '');

        return 'Edit '.Str::limit($name, 30);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->isPartiallyReturned = $this->isPartiallyReturnedPurchase();
        $itemCount = $this->record->items()->count();
        $this->usesPaginatedItems = $itemCount > self::PAGINATED_ITEMS_THRESHOLD;
        $this->itemsPage = 1;
        $recordedPaid = $this->getRecordedPaidAmount();

        $data['paginated_items_mode'] = $this->usesPaginatedItems;
        $data['items_page'] = $this->itemsPage;
        $data['items_per_page'] = self::ITEMS_PER_PAGE;
        $data['items_total'] = $itemCount;
        $data['items_last_page'] = $this->itemsLastPage($itemCount);
        $data['items'] = $this->usesPaginatedItems
            ? $this->itemsForPage($this->itemsPage)
            : $this->record->items->map(fn ($item) => $this->itemState($item))->toArray();

        $data['previous_paid_amount'] = $recordedPaid;
        $data['current_payment_amount'] = 0;
        $data['paid_amount'] = $recordedPaid;
        $data['subtotal'] = (float) ($data['subtotal'] ?? $this->record->subtotal ?? 0);
        $data['total_amount'] = (float) ($data['total_amount'] ?? $this->record->total_amount ?? 0);

        if (! array_key_exists('due_amount', $data) || $data['due_amount'] === null) {
            $totalAmount = (float) ($data['total_amount'] ?? 0);
            $data['due_amount'] = round(max(0, $totalAmount - $recordedPaid), 2);
        }

        $data['payment_date'] = now()->toDateString();
        $data['is_partial_return'] = $this->isPartiallyReturned;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        $guard = Filament::getCurrentPanel()->getAuthGuard();

        return [
            ViewAction::make()
                ->visible(fn () => auth($guard)->user()?->hasPermissionTo('purchases.view', $guard)),

            DeleteAction::make()
                ->color('danger')
                ->visible(fn () => auth($guard)->user()?->hasPermissionTo('purchases.delete', $guard)),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->isPartiallyReturnedPurchase()) {
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

        if ((bool) ($data['paginated_items_mode'] ?? false)) {
            unset($data['payment_date']);
            unset($data['items']);
            unset($data['paginated_items_mode']);
            unset($data['items_page']);
            unset($data['items_per_page']);
            unset($data['items_total']);
            unset($data['items_last_page']);

            $data['subtotal'] = (float) ($this->record->subtotal ?? 0);
            $data['total_amount'] = (float) ($this->record->total_amount ?? 0);
            $data['current_payment_amount'] = (float) ($data['current_payment_amount'] ?? 0);
            $this->preparePaymentDelta($data, (float) $data['total_amount']);
            unset($data['current_payment_amount']);
            $data['paid_amount'] = $this->getRecordedPaidAmount() + $this->paymentDelta;
            self::applyPaymentFields($data);

            return $data;
        }

        $items = $data['items'] ?? [];
        $currentPaymentAmount = (float) ($data['current_payment_amount'] ?? 0);
        unset($data['items']);
        unset($data['paginated_items_mode']);
        unset($data['items_page']);
        unset($data['items_per_page']);
        unset($data['items_total']);
        unset($data['items_last_page']);
        unset($data['current_payment_amount']);
        unset($data['payment_date']);
        $items = self::normalizeItems($items);

        $subtotal = collect($items)->sum(fn ($i) => (float) ($i['line_total'] ?? 0));
        $totalDiscount = 0.0;
        $totalTax = 0.0;

        foreach ($items as $item) {
            $lineTotal = (float) ($item['line_total'] ?? 0);
            $discountRate = (float) ($item['discount'] ?? 0);
            $taxRate = (float) ($item['tax'] ?? 0);

            $discountRate = max(0, min(100, $discountRate));
            $taxRate = max(0, min(100, $taxRate));

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
        if ($this->isPartiallyReturned && $this->paymentDelta == 0.0) {
            return;
        }

        DB::transaction(function () {

            $items = $this->form->getState()['items'] ?? [];
            $items = self::normalizeItems($items);

            if (! $this->isPartiallyReturned && ! (bool) ($this->form->getState()['paginated_items_mode'] ?? false)) {
                // Clear existing
                $this->record->items()->delete();

                foreach ($items as $item) {

                    $branch = Branch::select('id', 'business_id')
                        ->find($item['branch_id']);

                    if (! $branch) {
                        continue;
                    }

                    $purchaseItem = $this->record->items()->create([
                        'business_id' => $branch->business_id,
                        'branch_id' => $branch->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'line_total' => $item['line_total'],
                        'discount' => $item['discount'] ?? 0,
                        'tax' => $item['tax'] ?? 0,
                    ]);

                    // ✅ MATCH SALE
                    if (! empty($item['product_variant_id'])) {
                        $purchaseItem->variants()->create([
                            'product_variant_id' => $item['product_variant_id'],
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['unit_price'],
                            'line_total' => $item['line_total'],
                        ]);
                    }
                }
            }

            if (! $this->isPartiallyReturned && (bool) ($this->form->getState()['paginated_items_mode'] ?? false)) {
                $this->saveVisibleItemsPage();
            }

            if ($this->paymentDelta != 0.0) {
                PaymentLedgerService::recordPurchasePayment(
                    $this->record->fresh(),
                    $this->paymentDelta,
                    $this->paymentDate,
                    $this->paymentEntryType,
                );
            }
        });

        $purchase = $this->record->fresh(['payments']);
        if ($purchase) {
            app(OperationalLedgerPoster::class)->syncPurchase($purchase);
        }
    }

    public function previousItemsPage(): void
    {
        $this->changeItemsPage($this->itemsPage - 1);
    }

    public function nextItemsPage(): void
    {
        $this->changeItemsPage($this->itemsPage + 1);
    }

    public function changeItemsPage(int $page): void
    {
        if ($this->isPartiallyReturnedPurchase()) {
            return;
        }

        $state = $this->form->getState();

        if (! (bool) ($state['paginated_items_mode'] ?? false)) {
            return;
        }

        $this->saveVisibleItemsPage();

        $total = $this->record->items()->count();
        $lastPage = $this->itemsLastPage($total);
        $this->itemsPage = max(1, min($page, $lastPage));

        $this->form->fill([
            ...$state,
            'items' => $this->itemsForPage($this->itemsPage),
            'items_page' => $this->itemsPage,
            'items_total' => $total,
            'items_last_page' => $lastPage,
            'subtotal' => (float) ($this->record->subtotal ?? 0),
            'total_amount' => (float) ($this->record->total_amount ?? 0),
        ]);
    }

    private function saveVisibleItemsPage(): void
    {
        $items = self::normalizeItems($this->form->getState()['items'] ?? []);

        DB::transaction(function () use ($items): void {
            foreach ($items as $item) {
                $purchaseItemId = $item['purchase_item_id'] ?? null;

                if (! $purchaseItemId) {
                    continue;
                }

                $purchaseItem = $this->record->items()->find($purchaseItemId);

                if (! $purchaseItem) {
                    continue;
                }

                $branch = Branch::select('id', 'business_id')
                    ->find($item['branch_id']);

                if (! $branch) {
                    continue;
                }

                $purchaseItem->update([
                    'business_id' => $branch->business_id,
                    'branch_id' => $branch->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['line_total'],
                    'discount' => $item['discount'] ?? 0,
                    'tax' => $item['tax'] ?? 0,
                ]);

                $variant = $purchaseItem->variants()->first();

                if (! empty($item['product_variant_id'])) {
                    if ($variant) {
                        $variant->update([
                            'product_variant_id' => $item['product_variant_id'],
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['unit_price'],
                            'line_total' => $item['line_total'],
                        ]);
                    } else {
                        $purchaseItem->variants()->create([
                            'product_variant_id' => $item['product_variant_id'],
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['unit_price'],
                            'line_total' => $item['line_total'],
                        ]);
                    }
                } else {
                    $purchaseItem->variants()->delete();
                }
            }

            $this->syncRecordTotalsFromItems();
        });
    }

    private function syncRecordTotalsFromItems(): void
    {
        $items = $this->record->items()->get(['line_total', 'discount', 'tax']);
        $subtotal = 0.0;
        $totalDiscount = 0.0;
        $totalTax = 0.0;

        foreach ($items as $item) {
            $lineTotal = (float) ($item->line_total ?? 0);
            $discountRate = max(0, min(100, (float) ($item->discount ?? 0)));
            $taxRate = max(0, min(100, (float) ($item->tax ?? 0)));
            $discountAmount = $lineTotal * ($discountRate / 100);
            $taxableAmount = max(0, $lineTotal - $discountAmount);

            $subtotal += $lineTotal;
            $totalDiscount += $discountAmount;
            $totalTax += $taxableAmount * ($taxRate / 100);
        }

        $totalAmount = round($subtotal - $totalDiscount + $totalTax, 2);
        $paidAmount = min($totalAmount, (float) ($this->record->paid_amount ?? 0));

        $this->record->update([
            'subtotal' => round($subtotal, 2),
            'total_amount' => $totalAmount,
            'paid_amount' => round($paidAmount, 2),
            'due_amount' => round(max(0, $totalAmount - $paidAmount), 2),
            'payment_type' => $totalAmount > $paidAmount ? 'credit' : 'cash',
        ]);

        $this->record->refresh();
    }

    private function itemsForPage(int $page): array
    {
        return $this->record
            ->items()
            ->with('variants')
            ->orderBy('id')
            ->forPage($page, self::ITEMS_PER_PAGE)
            ->get()
            ->map(fn ($item) => $this->itemState($item))
            ->toArray();
    }

    private function itemState($item): array
    {
        return [
            'purchase_item_id' => $item->id,
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
        ];
    }

    private function itemsLastPage(int $total): int
    {
        return max(1, (int) ceil($total / self::ITEMS_PER_PAGE));
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
        $totalAmount = max(0, (float) ($data['total_amount'] ?? 0));
        $paidAmount = $data['paid_amount'] ?? null;

        $paidAmount = $paidAmount === null || $paidAmount === ''
            ? $totalAmount
            : (float) $paidAmount;

        $paidAmount = max(0, min($totalAmount, $paidAmount));
        $dueAmount = max(0, $totalAmount - $paidAmount);

        $data['paid_amount'] = round($paidAmount, 2);
        $data['due_amount'] = round($dueAmount, 2);
        $data['payment_type'] = $dueAmount > 0 ? 'credit' : 'cash';
    }

    public function isPartiallyReturnedPurchase(): bool
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
        $this->mountAction('reversePayment', [
            'paymentId' => $paymentId,
        ]);
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
                    Notification::make()
                        ->danger()
                        ->title('Invalid payment selected for reversal.')
                        ->send();

                    return;
                }

                $this->reversePayment($paymentId);
            });
    }

    public function reversePayment(string $paymentId): void
    {
        $payment = $this->record->payments()
            ->whereKey($paymentId)
            ->first();

        if (! $payment instanceof Payment || (float) ($payment->amount ?? 0) <= 0 || $payment->entry_type !== 'payment') {
            Notification::make()
                ->danger()
                ->title('Invalid payment selected for reversal.')
                ->send();

            return;
        }

        $payment->delete();
        PaymentLedgerService::syncPurchaseTotals($this->record->fresh());

        $this->record = $this->record->fresh(['items.variants', 'payments']);
        app(OperationalLedgerPoster::class)->syncPurchase($this->record);
        $this->form->fill($this->mutateFormDataBeforeFill($this->record->attributesToArray()));

        Notification::make()
            ->success()
            ->title('Payment deleted.')
            ->send();
    }
}
