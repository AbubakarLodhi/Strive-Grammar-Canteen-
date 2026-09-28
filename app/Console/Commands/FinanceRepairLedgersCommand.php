<?php

namespace App\Console\Commands;

use App\Models\JournalVoucher;
use App\Models\Merchant;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\Finance\FinanceLedger;
use App\Services\Finance\OperationalLedgerPoster;
use App\Services\Inventory\CanteenStockImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FinanceRepairLedgersCommand extends Command
{
    protected $signature = 'finance:repair-ledgers
                            {--merchant= : Limit to one merchant id}
                            {--dry-run : Show actions without writing}';

    protected $description = 'Provision system accounts, re-post purchases/sales to inventory/COGS, and clean orphan sale journal vouchers';

    public function handle(FinanceLedger $ledger, OperationalLedgerPoster $poster): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $merchantId = $this->option('merchant');

        $merchants = Merchant::query()
            ->when($merchantId, fn ($q) => $q->whereKey($merchantId))
            ->orderBy('name')
            ->get();

        if ($merchants->isEmpty()) {
            $this->warn('No merchants found.');

            return self::FAILURE;
        }

        foreach ($merchants as $merchant) {
            $this->components->info($merchant->name.' ('.$merchant->id.')');

            if (! $dryRun) {
                $ledger->provisionDefaultAccounts($merchant);
            } else {
                $this->line('  [dry-run] provision default accounts (incl. Inventory 1400 / COGS 5000)');
            }

            $purchases = Purchase::query()
                ->where('merchant_id', $merchant->id)
                ->with(['payments', 'vendor'])
                ->get()
                ->reject(fn (Purchase $purchase) => CanteenStockImporter::isOpeningStockPurchase($purchase));

            $this->line('  Purchases to re-post: '.$purchases->count());
            if (! $dryRun) {
                foreach ($purchases as $purchase) {
                    $poster->syncPurchase($purchase);
                }
            }

            $sales = Sale::query()
                ->withTrashed()
                ->where('merchant_id', $merchant->id)
                ->get();

            $postedSales = $sales->filter(fn (Sale $sale) => $sale->isPosted() && ! $sale->trashed());
            $this->line('  Posted sales to re-sync: '.$postedSales->count());
            if (! $dryRun) {
                foreach ($postedSales as $sale) {
                    $poster->syncSale($sale->fresh(['payments', 'items.product']) ?? $sale);
                }
            }

            $orphans = $this->repairOrphanSaleVouchers($merchant->id, $poster, $dryRun);
            $this->line('  Orphan/draft sale vouchers handled: '.$orphans);
        }

        $this->components->success($dryRun ? 'Dry run complete.' : 'Ledger repair complete.');

        return self::SUCCESS;
    }

    private function repairOrphanSaleVouchers(string $merchantId, OperationalLedgerPoster $poster, bool $dryRun): int
    {
        $saleMorph = (new Sale)->getMorphClass();
        $handled = 0;

        $vouchers = JournalVoucher::query()
            ->where('merchant_id', $merchantId)
            ->where('source_type', $saleMorph)
            ->whereNotNull('source_id')
            ->get();

        foreach ($vouchers as $voucher) {
            $sale = Sale::withTrashed()->find($voucher->source_id);

            if (! $sale) {
                $this->warn('  Removing orphan JV '.$voucher->voucher_no.' (sale missing)');
                if (! $dryRun) {
                    DB::transaction(function () use ($voucher): void {
                        $voucher->lines()->delete();
                        $voucher->forceDelete();
                    });
                }
                $handled++;

                continue;
            }

            if ($sale->trashed()) {
                $this->warn('  Sale '.$sale->sale_no.' is soft-deleted; removing JV '.$voucher->voucher_no);
                if (! $dryRun) {
                    $poster->forget($sale);
                }
                $handled++;

                continue;
            }

            if ($sale->isDraft()) {
                $this->warn('  Sale '.$sale->sale_no.' is draft but has JV '.$voucher->voucher_no.'; posting sale');
                if (! $dryRun) {
                    $sale->forceFill([
                        'status' => Sale::STATUS_POSTED,
                        'posted_at' => $sale->posted_at ?? now(),
                    ])->save();
                    $poster->syncSale($sale->fresh(['payments', 'items.product']) ?? $sale);
                }
                $handled++;
            }
        }

        return $handled;
    }
}
