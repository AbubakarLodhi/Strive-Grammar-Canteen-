<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Services\Inventory\CanteenStockImporter;
use Illuminate\Database\Seeder;

class CanteenStockSeeder extends Seeder
{
    public function run(): void
    {
        $merchant = Merchant::query()->where('email', 'info@strive.com')->first();

        if (! $merchant) {
            $this->command?->warn('Strive merchant not found. Run StriveAccountsSeeder first.');

            return;
        }

        $path = database_path('data/canteen-stock.xls');

        if (! is_file($path)) {
            $path = storage_path('app/imports/canteen-stock.xls');
        }

        if (! is_file($path)) {
            $this->command?->error("Missing stock file at {$path}");

            return;
        }

        $result = app(CanteenStockImporter::class)->importFromPath($path, $merchant);

        $this->command?->info(sprintf(
            'Canteen stock imported: %d created, %d updated, %d rows, qty %.0f',
            $result['products_created'],
            $result['products_updated'],
            $result['rows_imported'],
            $result['total_quantity'],
        ));
    }
}
