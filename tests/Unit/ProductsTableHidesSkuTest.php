<?php

namespace Tests\Unit;

use Tests\TestCase;

class ProductsTableHidesSkuTest extends TestCase
{
    public function test_products_table_does_not_show_sku_column(): void
    {
        $table = file_get_contents(app_path('Filament/Resources/Products/Tables/ProductsTable.php'));

        $this->assertStringNotContainsString("TextColumn::make('sku')", $table);
        $this->assertStringContainsString("orWhere('sku'", $table);
    }

    public function test_product_option_labels_do_not_include_sku(): void
    {
        $availability = file_get_contents(app_path('Support/ProductStockAvailability.php'));
        $purchaseForm = file_get_contents(app_path('Filament/Resources/Purchases/Schemas/PurchaseForm.php'));

        $this->assertStringNotContainsString('{$product->name} ({$product->sku})', $availability);
        $this->assertStringNotContainsString('{$product->name} ({$product->sku})', $purchaseForm);
    }
}
