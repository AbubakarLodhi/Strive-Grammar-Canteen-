<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\Finance\OperationalLedgerPoster;
use Illuminate\Support\Facades\DB;

class SaleDeletionService
{
    public function __construct(
        private OperationalLedgerPoster $ledgerPoster,
    ) {}

    /**
     * Remove ledger links, payments, and reminders before the sale soft-delete.
     */
    public function prepare(Sale $sale): void
    {
        DB::transaction(function () use ($sale): void {
            $sale->loadMissing(['returns', 'payments', 'creditReminders']);

            foreach ($sale->returns as $return) {
                if ($return instanceof SaleReturn) {
                    $this->ledgerPoster->forget($return);
                }
            }

            $this->ledgerPoster->forget($sale);

            $sale->payments()->get()->each->delete();
            $sale->creditReminders()->get()->each->delete();
        });
    }

    public function delete(Sale $sale): void
    {
        DB::transaction(function () use ($sale): void {
            $this->prepare($sale);
            $sale->delete();
        });
    }
}
