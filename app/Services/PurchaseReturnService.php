<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseItemVariant;
use App\Models\PurchaseReturn;
use App\Services\Finance\OperationalLedgerPoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseReturnService
{
    public static function createReturn(Purchase $purchase, array $data): void
    {
        DB::transaction(function () use ($purchase, $data) {

            $purchase->loadMissing('items.product', 'items.variants');

            $returnItems = [];
            $subtotal = 0.0;
            $totalDiscount = 0.0;
            $totalTax = 0.0;

            foreach ($data['items'] as $item) {

                if ($item['quantity'] <= 0) {
                    continue;
                }

                $purchaseItem = $purchase->items()
                    ->where('id', $item['purchase_item_id'])
                    ->first();

                if (! $purchaseItem) {
                    continue;
                }

                $remainingQty = (int) $purchaseItem->quantity;

                if ($item['quantity'] > $remainingQty) {
                    $productName = $purchaseItem->product?->name ?? 'Product';
                    throw new \Exception(
                        "Return quantity for {$productName} cannot exceed remaining quantity ({$remainingQty})."
                    );
                }

                $lineTotal = (float) $purchaseItem->unit_price * (int) $item['quantity'];
                $discountRate = (float) ($purchaseItem->discount ?? 0);
                $taxRate = (float) ($purchaseItem->tax ?? 0);
                $discountAmount = $lineTotal * ($discountRate / 100);
                $taxableAmount = $lineTotal - $discountAmount;
                $taxAmount = $taxableAmount * ($taxRate / 100);

                $variantAllocations = [];
                $variantRows = $purchaseItem->variants()->get();
                $returnQty = (int) $item['quantity'];

                if ($variantRows->isNotEmpty()) {
                    $variantAllocations = self::buildVariantAllocations(
                        $variantRows,
                        $returnQty,
                        (float) $purchaseItem->unit_price
                    );

                    $allocatedQty = (int) collect($variantAllocations)->sum('quantity');
                    if ($allocatedQty !== $returnQty) {
                        $productName = $purchaseItem->product?->name ?? 'Product';
                        throw new \Exception(
                            "Return quantity for {$productName} cannot exceed available variant quantity."
                        );
                    }
                }

                $returnItems[] = [
                    'data' => [
                        'purchase_item_id' => $purchaseItem->id,
                        'business_id' => $purchaseItem->business_id,
                        'branch_id' => $purchaseItem->branch_id,
                        'product_id' => $purchaseItem->product_id,
                        'quantity' => $returnQty,
                        'unit_price' => $purchaseItem->unit_price,
                        'line_total' => $lineTotal,
                        'discount' => $purchaseItem->discount,
                        'tax' => $purchaseItem->tax,
                    ],
                    'variants' => $variantAllocations,
                ];

                $subtotal += $lineTotal;
                $totalDiscount += $discountAmount;
                $totalTax += $taxAmount;
            }

            if (! $returnItems) {
                throw new \Exception('No return items with quantity greater than zero.');
            }

            $return = PurchaseReturn::create([
                'merchant_id' => $purchase->merchant_id,
                'purchase_id' => $purchase->id,
                'return_no' => 'PRET-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'return_date' => $data['return_date'],
                'subtotal' => $subtotal,
                'total_discount' => $totalDiscount,
                'total_tax' => $totalTax,
                'total_amount' => $subtotal - $totalDiscount + $totalTax,
                'reason' => $data['reason'],
                'created_by' => auth()->id(),
            ]);

            foreach ($returnItems as $returnItem) {
                $itemModel = $return->items()->create($returnItem['data']);

                if (! empty($returnItem['variants'])) {
                    $itemModel->variants()->createMany($returnItem['variants']);
                }

                /** @var PurchaseItem|null $sourceItem */
                $sourceItem = PurchaseItem::query()->find($returnItem['data']['purchase_item_id'] ?? null);

                self::reduceSourcePurchaseItem(
                    $sourceItem,
                    (int) ($returnItem['data']['quantity'] ?? 0),
                    $returnItem['variants'] ?? []
                );
            }

            self::recalculatePurchaseTotals($purchase);
            app(OperationalLedgerPoster::class)->syncPurchaseReturn($return->fresh(['purchase.payments']));
        });
    }

    public static function deleteReturn(PurchaseReturn $return): void
    {
        DB::transaction(function () use ($return) {
            $return->loadMissing('items.variants');

            foreach ($return->items as $item) {
                /** @var PurchaseItem|null $sourceItem */
                $sourceItem = PurchaseItem::query()->find($item->purchase_item_id);

                self::restoreSourcePurchaseItem($sourceItem, $item);

                $item->variants()->delete();
                $item->delete();
            }

            $purchase = $return->purchase;
            app(OperationalLedgerPoster::class)->forget($return);
            $return->delete();

            if ($purchase) {
                self::recalculatePurchaseTotals($purchase);
            }
        });
    }

    protected static function recalculatePurchaseTotals(Purchase $purchase): void
    {
        $items = $purchase->items()->get();

        $subtotal = (float) $items->sum('line_total');
        $totalDiscount = 0.0;
        $totalTax = 0.0;

        foreach ($items as $item) {
            $lineTotal = (float) ($item->line_total ?? 0);
            $discountRate = (float) ($item->discount ?? 0);
            $taxRate = (float) ($item->tax ?? 0);

            $discountAmount = $lineTotal * ($discountRate / 100);
            $taxableAmount = $lineTotal - $discountAmount;
            $taxAmount = $taxableAmount * ($taxRate / 100);

            $totalDiscount += $discountAmount;
            $totalTax += $taxAmount;
        }

        $newTotal = $subtotal - $totalDiscount + $totalTax;

        $purchase->update([
            'subtotal' => $subtotal,
            'total_amount' => $newTotal,
        ]);

        PaymentLedgerService::syncPurchaseTotals($purchase->refresh());
    }

    protected static function buildVariantAllocations($variantRows, int $returnQty, float $fallbackUnitPrice): array
    {
        $allocations = [];
        $remaining = $returnQty;

        foreach ($variantRows as $variantRow) {
            if ($remaining <= 0) {
                break;
            }

            $availableQty = max(0, (int) ($variantRow->quantity ?? 0));
            if ($availableQty <= 0) {
                continue;
            }

            $allocQty = min($availableQty, $remaining);
            $variantUnit = (float) ($variantRow->unit_price ?? $fallbackUnitPrice);

            $allocations[] = [
                'product_variant_id' => $variantRow->product_variant_id,
                'quantity' => $allocQty,
                'unit_price' => $variantUnit,
                'line_total' => $variantUnit * $allocQty,
            ];

            $remaining -= $allocQty;
        }

        return $allocations;
    }

    protected static function reduceSourcePurchaseItem(?PurchaseItem $sourceItem, int $returnedQty, array $variantAllocations): void
    {
        if (! $sourceItem) {
            return;
        }

        if (! empty($variantAllocations) && $sourceItem->variants()->exists()) {
            $variantRows = $sourceItem->variants()->get()->keyBy('product_variant_id');

            foreach ($variantAllocations as $allocation) {
                $variantId = $allocation['product_variant_id'] ?? null;
                $qtyToReduce = max(0, (int) ($allocation['quantity'] ?? 0));

                if (! $variantId || $qtyToReduce <= 0) {
                    continue;
                }

                /** @var PurchaseItemVariant|null $sourceVariant */
                $sourceVariant = $variantRows->get($variantId);
                if (! $sourceVariant) {
                    continue;
                }

                $newQty = max(0, (int) $sourceVariant->quantity - $qtyToReduce);
                $lineTotal = (float) $sourceVariant->unit_price * $newQty;
                $sourceVariant->update([
                    'quantity' => $newQty,
                    'line_total' => $lineTotal,
                ]);
            }

            $sourceItem->refresh();
            $sourceItem->loadMissing('variants');
            $newQty = (int) $sourceItem->variants->sum('quantity');
            $lineTotal = (float) $sourceItem->variants->sum('line_total');
        } else {
            $newQty = max(0, (int) $sourceItem->quantity - $returnedQty);
            $lineTotal = (float) $sourceItem->unit_price * $newQty;
        }

        $sourceItem->update([
            'quantity' => $newQty,
            'line_total' => $lineTotal,
        ]);
    }

    protected static function restoreSourcePurchaseItem(?PurchaseItem $sourceItem, $returnItem): void
    {
        if (! $sourceItem) {
            return;
        }

        $returnVariantRows = $returnItem->variants()->get();

        if ($returnVariantRows->isNotEmpty() && $sourceItem->variants()->exists()) {
            $sourceVariantRows = $sourceItem->variants()->get()->keyBy('product_variant_id');

            foreach ($returnVariantRows as $returnVariant) {
                $variantId = $returnVariant->product_variant_id;
                $qtyToRestore = max(0, (int) ($returnVariant->quantity ?? 0));

                if (! $variantId || $qtyToRestore <= 0) {
                    continue;
                }

                /** @var PurchaseItemVariant|null $sourceVariant */
                $sourceVariant = $sourceVariantRows->get($variantId);

                if (! $sourceVariant) {
                    $sourceVariant = $sourceItem->variants()->create([
                        'product_variant_id' => $variantId,
                        'quantity' => 0,
                        'unit_price' => (float) ($returnVariant->unit_price ?? $sourceItem->unit_price),
                        'line_total' => 0,
                    ]);
                    $sourceVariantRows->put($variantId, $sourceVariant);
                }

                $newQty = (int) $sourceVariant->quantity + $qtyToRestore;
                $lineTotal = (float) $sourceVariant->unit_price * $newQty;
                $sourceVariant->update([
                    'quantity' => $newQty,
                    'line_total' => $lineTotal,
                ]);
            }

            $sourceItem->refresh();
            $sourceItem->loadMissing('variants');
            $newQty = (int) $sourceItem->variants->sum('quantity');
            $lineTotal = (float) $sourceItem->variants->sum('line_total');
        } else {
            $newQty = (int) $sourceItem->quantity + (int) ($returnItem->quantity ?? 0);
            $lineTotal = (float) $sourceItem->unit_price * $newQty;
        }

        $sourceItem->update([
            'quantity' => $newQty,
            'line_total' => $lineTotal,
        ]);
    }
}
