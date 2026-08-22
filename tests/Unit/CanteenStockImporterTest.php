<?php

namespace Tests\Unit;

use App\Services\Inventory\CanteenStockImporter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CanteenStockImporterTest extends TestCase
{
    public function test_parse_spreadsheet_reads_all_product_rows_including_zero_qty(): void
    {
        $path = storage_path('app/testing-canteen-stock.xlsx');
        @mkdir(dirname($path), 0777, true);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Canteen Stock'],
            [],
            [],
            ['Sr #', 'Product Name', 'Qty', 'Pr Price', 'Sell Price'],
            [1, 'Polo Shirt Red', 25, 650, 900],
            [2, 'Islamiat Cp 3', 0, 457, 531],
            [3, 'Books Bindings', '=10+5', 45, 80],
        ]);

        (new Xlsx($spreadsheet))->save($path);

        $rows = (new CanteenStockImporter)->parseSpreadsheet($path);

        $this->assertCount(3, $rows);
        $this->assertSame('Polo Shirt Red', $rows[0]['name']);
        $this->assertEquals(25.0, $rows[0]['quantity']);
        $this->assertEquals(0.0, $rows[1]['quantity']);
        $this->assertEquals(15.0, $rows[2]['quantity']);

        @unlink($path);
    }

    public function test_real_canteen_stock_sheet_contains_every_product_row(): void
    {
        $path = database_path('data/canteen-stock.xls');

        $this->assertFileExists($path);

        $rows = (new CanteenStockImporter)->parseSpreadsheet($path);

        $this->assertCount(366, $rows);
        $this->assertSame('Polo Shirt 18 Half sleeves Red', $rows[0]['name']);
        $this->assertEquals(25.0, $rows[0]['quantity']);
        $this->assertEquals(51.0, $rows[3]['quantity']);
        $this->assertSame('Books Bindings', $rows[array_key_last($rows)]['name']);
        $this->assertEquals(3600.0, $rows[array_key_last($rows)]['quantity']);
        $this->assertEquals(20261.0, array_sum(array_column($rows, 'quantity')));
        $this->assertTrue(collect($rows)->contains(fn (array $row): bool => $row['name'] === 'Belt'));
        $this->assertTrue(collect($rows)->contains(fn (array $row): bool => $row['name'] === 'Belt Buckle'));
    }
}
