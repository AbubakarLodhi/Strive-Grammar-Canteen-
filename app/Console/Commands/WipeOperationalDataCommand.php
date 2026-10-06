<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Services\Finance\FinanceLedger;
use App\Services\Inventory\CanteenStockImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WipeOperationalDataCommand extends Command
{
    protected $signature = 'app:wipe-operational-data
                            {--force : Run without confirmation}
                            {--reimport-stock : Re-import opening stock after wipe}
                            {--merchant= : Merchant email to re-import stock for (default: all merchants)}';

    protected $description = 'Wipe sales/purchases/stock/ledger test data while keeping merchant/staff logins, branches, and customers';

    /**
     * Operational / transactional tables cleared for a clean go-live.
     * Login accounts, branches, businesses, and customers are intentionally omitted.
     *
     * @var list<string>
     */
    private const WIPE_TABLES = [
        'journal_voucher_lines',
        'journal_vouchers',
        'sale_item_variants',
        'sale_items',
        'sale_return_item_variants',
        'sale_return_items',
        'sale_returns',
        'sales',
        'purchase_item_variants',
        'purchase_items',
        'purchase_return_item_variants',
        'purchase_return_items',
        'purchase_returns',
        'purchases',
        'payments',
        'expense_items',
        'expenses',
        'payrolls',
        'cash_vouchers',
        'cash_flows',
        'bank_reconciliation_items',
        'bank_reconciliations',
        'bank_cheques',
        'cheque_books',
        'bank_deposits',
        'online_bank_transfers',
        'credit_reminders',
        'outbound_messages',
        'product_variant_values',
        'product_variants',
        'product_option_values',
        'product_options',
        'business_products',
        'branch_products',
        'attachments',
        'products',
        'adds_on',
        'brand_category',
        'brand_models',
        'brands',
        'categories',
        'vendor_businesses',
        'vendor_branches',
        'vendors',
        'ledger_accounts',
        'assets',
        'asset_types',
        'invoice_dynamic_fields',
        'invoice_dynamic_groups',
        'invoice_daily_slip_counters',
        'orders',
        'audits',
    ];

    /**
     * Tables that must never be wiped (login + core structure).
     *
     * @var list<string>
     */
    private const PRESERVE_TABLES = [
        'merchants',
        'users',
        'branches',
        'businesses',
        'customers',
        'customer_businesses',
        'customer_branches',
        'countries',
        'cities',
        'roles',
        'permissions',
        'model_has_roles',
        'model_has_permissions',
        'role_has_permissions',
    ];

    public function handle(FinanceLedger $ledger, CanteenStockImporter $importer): int
    {
        if (! $this->option('force') && ! $this->confirm('Wipe operational/test data but keep merchant/staff logins, branches, and customers?')) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $host = (string) config("database.connections.{$connection}.host");

        $this->components->warn("Target database: {$database} @ {$host}");

        $merchantEmailsBefore = Merchant::query()->pluck('email')->all();
        $this->components->info('Preserving merchants: '.implode(', ', $merchantEmailsBefore ?: ['(none)']));
        $this->components->info('Also preserving: branches, businesses, customers');

        $overlap = array_values(array_intersect(self::WIPE_TABLES, self::PRESERVE_TABLES));

        if ($overlap !== []) {
            $this->error('Safety check failed. These preserve tables are listed for wipe: '.implode(', ', $overlap));

            return self::FAILURE;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        $cleared = 0;

        foreach (self::WIPE_TABLES as $table) {
            if (in_array($table, self::PRESERVE_TABLES, true)) {
                $this->warn("  skipped preserved table {$table}");

                continue;
            }

            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->truncate();
            $cleared++;
            $this->line("  truncated {$table}");
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        $this->components->info("Cleared {$cleared} tables.");

        Merchant::query()->each(function (Merchant $merchant) use ($ledger): void {
            $ledger->provisionDefaultAccounts($merchant);
            $this->line("  provisioned ledger accounts for {$merchant->email}");
        });

        if ($this->option('reimport-stock')) {
            $merchants = Merchant::query()
                ->when(
                    filled($this->option('merchant')),
                    fn ($query) => $query->where('email', $this->option('merchant'))
                )
                ->get();

            if ($merchants->isEmpty()) {
                $this->error('No merchant found for stock re-import.');

                return self::FAILURE;
            }

            $path = database_path('data/canteen-stock.xls');

            if (! is_file($path)) {
                $path = storage_path('app/imports/canteen-stock.xls');
            }

            if (! is_file($path)) {
                $this->error('Stock file not found. Place canteen-stock.xls under database/data or storage/app/imports.');

                return self::FAILURE;
            }

            foreach ($merchants as $merchant) {
                $result = $importer->importFromPath($path, $merchant);
                $this->components->info(sprintf(
                    'Stock imported for %s: %d created, %d updated, qty %s',
                    $merchant->email,
                    $result['products_created'],
                    $result['products_updated'],
                    number_format($result['total_quantity']),
                ));
            }
        }

        $merchantEmailsAfter = Merchant::query()->pluck('email')->all();
        $this->components->info('Merchants still present: '.implode(', ', $merchantEmailsAfter ?: ['(none)']));
        $this->components->success('Operational data wipe complete. Logins, branches, and customers were not changed.');

        return self::SUCCESS;
    }
}
