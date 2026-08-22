<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Services\Inventory\CanteenStockImporter;
use Illuminate\Console\Command;

class ImportCanteenStockCommand extends Command
{
    protected $signature = 'canteen:import-stock
                            {path? : Path to the stock .xls/.xlsx file}
                            {--merchant=info@strive.com : Merchant email}';

    protected $description = 'Import canteen products and stock quantities from an Excel stock sheet';

    public function handle(CanteenStockImporter $importer): int
    {
        $path = $this->argument('path')
            ?: database_path('data/canteen-stock.xls');

        if (! is_file($path)) {
            $this->error("Stock file not found: {$path}");

            return self::FAILURE;
        }

        $merchant = Merchant::query()->where('email', $this->option('merchant'))->first();

        if (! $merchant) {
            $this->error('Merchant not found. Seed Strive accounts first.');

            return self::FAILURE;
        }

        $result = $importer->importFromPath($path, $merchant);

        $this->info(sprintf(
            'Imported %d rows (%d created, %d updated). Total sheet qty: %s',
            $result['rows_imported'],
            $result['products_created'],
            $result['products_updated'],
            number_format($result['total_quantity']),
        ));

        return self::SUCCESS;
    }
}
