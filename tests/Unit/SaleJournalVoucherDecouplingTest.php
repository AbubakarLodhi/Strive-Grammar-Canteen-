<?php

namespace Tests\Unit;

use Tests\TestCase;

class SaleJournalVoucherDecouplingTest extends TestCase
{
    public function test_sale_pages_sync_cash_sales_to_ledger_for_cash_in_hand(): void
    {
        $create = file_get_contents(app_path('Filament/Resources/Sales/Pages/CreateSale.php'));
        $edit = file_get_contents(app_path('Filament/Resources/Sales/Pages/EditSale.php'));
        $table = file_get_contents(app_path('Filament/Resources/Sales/Tables/SalesTable.php'));

        $this->assertStringContainsString('syncSale(', $create);
        $this->assertStringContainsString('OperationalLedgerPoster', $create);
        $this->assertStringContainsString('syncSale(', $edit);
        $this->assertStringContainsString('SaleDeletionService', $edit);
        $this->assertStringContainsString('SaleDeletionService', $table);
    }

    public function test_journal_voucher_form_includes_vendor_payment_fields(): void
    {
        $form = file_get_contents(app_path('Filament/Resources/JournalVouchers/Schemas/JournalVoucherForm.php'));

        $this->assertStringContainsString("make('vendor_id')", $form);
        $this->assertStringContainsString("make('payment_amount')", $form);
        $this->assertStringContainsString('Payment to', $form);
    }

    public function test_journal_voucher_list_shows_only_manual_vouchers(): void
    {
        $resource = file_get_contents(app_path('Filament/Resources/JournalVouchers/JournalVoucherResource.php'));

        $this->assertStringContainsString("whereNull('source_type')", $resource);
        $this->assertStringNotContainsString('orWhereNotIn', $resource);
    }

    public function test_journal_voucher_form_can_fill_from_purchase(): void
    {
        $form = file_get_contents(app_path('Filament/Resources/JournalVouchers/Schemas/JournalVoucherForm.php'));

        $this->assertStringContainsString("make('purchase_id')", $form);
        $this->assertStringContainsString('From Purchase', $form);
        $this->assertStringContainsString('applyPurchaseLines', $form);
    }
}
