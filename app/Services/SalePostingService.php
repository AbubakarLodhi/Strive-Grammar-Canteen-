<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\User;
use App\Services\Finance\OperationalLedgerPoster;
use App\Support\ProductStockAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalePostingService
{
    public function __construct(
        private OperationalLedgerPoster $ledgerPoster,
    ) {}

    public function post(Sale $sale, User|string|null $postedBy = null): Sale
    {
        if ($sale->isPosted()) {
            return $sale->fresh(['payments', 'items.variants']) ?? $sale;
        }

        $sale->loadMissing(['items.variants', 'payments']);

        $stockItems = $sale->items->map(function ($item): array {
            $variantId = $item->variants->first()?->product_variant_id;

            return [
                'product_id' => $item->product_id,
                'product_variant_id' => $variantId,
                'branch_id' => $item->branch_id,
                'quantity' => $item->quantity,
            ];
        })->all();

        $stockError = ProductStockAvailability::validateSaleItemsStock($stockItems, $sale->id);

        if ($stockError !== null) {
            throw ValidationException::withMessages([
                'sale' => $stockError,
            ]);
        }

        $postedById = $postedBy instanceof User ? $postedBy->id : $postedBy;

        return DB::transaction(function () use ($sale, $postedById): Sale {
            $sale->forceFill([
                'status' => Sale::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => $postedById,
            ])->save();

            $fresh = $sale->fresh(['payments']) ?? $sale;

            if ((float) $fresh->paid_amount > 0 && $fresh->payments()->doesntExist()) {
                PaymentLedgerService::recordSalePayment(
                    $fresh,
                    (float) $fresh->paid_amount,
                    $fresh->sale_date,
                );
            }

            $this->ledgerPoster->syncSale($fresh->fresh(['payments']) ?? $fresh);

            return $fresh->fresh(['payments', 'items.variants', 'customer']) ?? $fresh;
        });
    }
}
