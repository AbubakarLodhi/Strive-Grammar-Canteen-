<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemVariant;
use App\Models\SaleReturn;
use App\Services\Finance\OperationalLedgerPoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaleReturnService
{
    public static function createReturn(Sale $sale, array $data): void
    {
        DB::transaction(function () use ($sale, $data) {

            $sale->loadMissing('items.product', 'items.variants');

            $returnItems = [];
            $subtotal = 0.0;
            $totalDiscount = 0.0;
            $totalTax = 0.0;

            foreach ($data['items'] as $item) {

                if ($item['quantity'] <= 0) {
                    continue;
                }

                $saleItem = $sale->items()
                    ->where('id', $item['sale_item_id'])
                    ->first();

                if (! $saleItem) {
                    continue;
                }

                $remainingQty = (int) $saleItem->quantity;

                if ($item['quantity'] > $remainingQty) {
                    $productName = $saleItem->product?->name ?? 'Product';
                    throw new \Exception(
                        "Return quantity for {$productName} cannot exceed remaining quantity ({$remainingQty})."
                    );
                }

                $lineTotal = (float) $saleItem->unit_price * (int) $item['quantity'];
                $discountRate = (float) ($saleItem->discount ?? 0);
                $taxRate = (float) ($saleItem->tax ?? 0);
                $discountAmount = $lineTotal * ($discountRate / 100);
                $taxableAmount = $lineTotal - $discountAmount;
                $taxAmount = $taxableAmount * ($taxRate / 100);

                $variantAllocations = [];
                $variantRows = $saleItem->variants()->get();
                $returnQty = (int) $item['quantity'];

                if ($variantRows->isNotEmpty()) {
                    $variantAllocations = self::buildVariantAllocations(
                        $variantRows,
                        $returnQty,
                        (float) $saleItem->unit_price
                    );

                    $allocatedQty = (int) collect($variantAllocations)->sum('quantity');
                    if ($allocatedQty !== $returnQty) {
                        $productName = $saleItem->product?->name ?? 'Product';
                        throw new \Exception(
                            "Return quantity for {$productName} cannot exceed available variant quantity."
                        );
                    }
                }

                $returnItems[] = [
                    'data' => [
                        'sale_item_id' => $saleItem->id,
                        'business_id' => $saleItem->business_id,
                        'branch_id' => $saleItem->branch_id,
                        'product_id' => $saleItem->product_id,
                        'quantity' => $returnQty,
                        'unit_price' => $saleItem->unit_price,
                        'line_total' => $lineTotal,
                        'discount' => $saleItem->discount,
                        'tax' => $saleItem->tax,
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

            $return = SaleReturn::create([
                'merchant_id' => $sale->merchant_id,
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'return_no' => 'RET-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
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

                /** @var SaleItem|null $sourceItem */
                $sourceItem = SaleItem::query()->find($returnItem['data']['sale_item_id'] ?? null);

                self::reduceSourceSaleItem(
                    $sourceItem,
                    (int) ($returnItem['data']['quantity'] ?? 0),
                    $returnItem['variants'] ?? []
                );
            }

            self::recalculateSaleTotals($sale);
            app(OperationalLedgerPoster::class)->syncSaleReturn($return->fresh(['sale.payments']));
        });
    }

    public static function deleteReturn(SaleReturn $return): void
    {
        DB::transaction(function () use ($return) {
            $return->loadMissing('items.variants');

            foreach ($return->items as $item) {
                /** @var SaleItem|null $sourceItem */
                $sourceItem = SaleItem::query()->find($item->sale_item_id);

                self::restoreSourceSaleItem($sourceItem, $item);

                $item->variants()->delete();
                $item->delete();
            }

            $sale = $return->sale;
            app(OperationalLedgerPoster::class)->forget($return);
            $return->delete();

            if ($sale) {
                self::recalculateSaleTotals($sale);
            }
        });
    }

    protected static function recalculateSaleTotals(Sale $sale): void
    {
        $items = $sale->items()->get();
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

        $sale->update([
            'subtotal' => $subtotal,
            'total_amount' => $newTotal,
        ]);

        PaymentLedgerService::syncSaleTotals($sale->refresh());
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

    protected static function reduceSourceSaleItem(?SaleItem $sourceItem, int $returnedQty, array $variantAllocations): void
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

                /** @var SaleItemVariant|null $sourceVariant */
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

    protected static function restoreSourceSaleItem(?SaleItem $sourceItem, $returnItem): void
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

                /** @var SaleItemVariant|null $sourceVariant */
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
