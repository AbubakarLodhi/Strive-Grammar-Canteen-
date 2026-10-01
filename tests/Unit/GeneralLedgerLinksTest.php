<?php

namespace Tests\Unit;

use App\Filament\Pages\GeneralLedger;
use Tests\TestCase;

class GeneralLedgerLinksTest extends TestCase
{
    public function test_it_extracts_purchase_number_from_ledger_description(): void
    {
        $this->assertSame(
            'PUR-20260929-12CD7E',
            GeneralLedger::extractPurchaseNo(
                'Purchase PUR-20260929-12CD7E — Mustaqeem Leather House — Purchase PUR-20260929-12CD7E amount payable'
            )
        );

        $this->assertNull(
            GeneralLedger::extractPurchaseNo('Vendor payment — Mustaqeem Leather House — Payment to Mustaqeem Leather House')
        );

        $this->assertNull(GeneralLedger::extractPurchaseNo(null));
    }
}
