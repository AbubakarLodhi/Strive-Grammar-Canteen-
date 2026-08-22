<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Enums\AttachmentMetaType;
use App\Enums\AttachmentType;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Branch;
use App\Models\Merchant;
use App\Services\Finance\OperationalLedgerPoster;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\PaymentLedgerService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        $purchase = $this->record->fresh(['vendor', 'merchant']);

        if (! $purchase) {
            return;
        }

        try {
            app(NotificationDispatcher::class)->dispatchPurchaseCreated($purchase);
        } catch (Throwable $exception) {
            Log::error('purchase_created notification failed.', [
                'purchase_id' => $purchase->id,
                'error' => $exception->getMessage(),
            ]);
        }

        app(OperationalLedgerPoster::class)->syncPurchase($purchase);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {

            /* -----------------------------
             | EXTRACT ITEMS
             ----------------------------- */
            $items = $data['items'] ?? [];
            unset($data['items']);
            $items = self::normalizeItems($items);
            $paymentDate = $data['payment_date'] ?? null;
            unset($data['payment_date']);

            /* -----------------------------
             | CREATED BY
             ----------------------------- */
            $panel = Filament::getCurrentPanel();
            $guard = $panel?->getAuthGuard();
            $user = Filament::auth()->user();

            $data['created_by'] = ($guard === 'staff' && $user)
                ? $user->id
                : null;

            /* -----------------------------
             | TOTALS
             ----------------------------- */
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
            self::applyPaymentFields($data);

            /* -----------------------------
             | CREATE PURCHASE
             ----------------------------- */
            $purchase = static::getModel()::create($data);

            if ((float) ($data['paid_amount'] ?? 0) > 0) {
                PaymentLedgerService::recordPurchasePayment(
                    $purchase,
                    (float) $data['paid_amount'],
                    $paymentDate ?? $data['purchase_date'] ?? null
                );
            }

            /* -----------------------------
             | CREATE ITEMS + VARIANTS ✅
             ----------------------------- */
            foreach ($items as $item) {

                $branch = Branch::select('id', 'business_id')
                    ->find($item['branch_id']);

                if (! $branch) {
                    continue;
                }

                $purchaseItem = $purchase->items()->create([
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

            /* -----------------------------
             | MERCHANT LOGO (UNCHANGED)
             ----------------------------- */
            $state = $this->form->getRawState();

            if (array_key_exists('merchant_logo', $state)) {
                $merchant = Filament::auth()->user() instanceof Merchant
                    ? Filament::auth()->user()
                    : Filament::auth()->user()?->merchant;

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

            return $purchase;
        });
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
}
