<?php

namespace App\Services;

use App\Models\InvoiceDailySlipCounter;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class InvoiceSlipCounterService
{
    public function nextNumber(string $merchantId, ?CarbonInterface $date = null): int
    {
        $counterDate = ($date ?? now())->toDateString();

        return DB::transaction(function () use ($merchantId, $counterDate): int {
            $counter = InvoiceDailySlipCounter::query()
                ->where('merchant_id', $merchantId)
                ->whereDate('counter_date', $counterDate)
                ->lockForUpdate()
                ->first();

            if (! $counter) {
                InvoiceDailySlipCounter::query()->create([
                    'merchant_id' => $merchantId,
                    'counter_date' => $counterDate,
                    'current_count' => 1,
                ]);

                return 1;
            }

            $counter->increment('current_count');

            return (int) $counter->current_count;
        });
    }
}
