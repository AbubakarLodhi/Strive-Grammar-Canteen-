<?php

namespace App\Filament\Resources\LedgerAccounts\Pages;

use App\Filament\Resources\LedgerAccounts\LedgerAccountResource;
use App\Models\LedgerAccount;
use App\Services\Finance\FinanceLedger;
use App\Services\Inventory\CanteenStockImporter;
use App\Support\FinanceAccess;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

class ListLedgerAccounts extends ListRecords
{
    protected static string $resource = LedgerAccountResource::class;

    public function mount(): void
    {
        parent::mount();

        $merchantId = FinanceAccess::merchantId();

        if ($merchantId) {
            $ledger = app(FinanceLedger::class);
            $ledger->purgeOpeningStockLedger($merchantId);
            $ledger->backfillVendorPayableAccounts($merchantId);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => FinanceAccess::can('ledger_accounts', 'create')),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                Html::make(fn (): HtmlString => new HtmlString($this->vendorPayablesTotalHtml())),
            ]);
    }

    protected function vendorPayablesTotalHtml(): string
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return '';
        }

        $accounts = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->whereNotNull('vendor_id')
            ->where('name', '!=', CanteenStockImporter::OPENING_VENDOR_NAME)
            ->whereDoesntHave('vendor', function ($query): void {
                $query->where(function ($builder): void {
                    $builder
                        ->where('name', CanteenStockImporter::OPENING_VENDOR_NAME)
                        ->orWhere('reference', CanteenStockImporter::OPENING_VENDOR_REFERENCE)
                        ->orWhere('email', CanteenStockImporter::OPENING_VENDOR_EMAIL);
                });
            })
            ->get();

        if ($accounts->isEmpty()) {
            return '';
        }

        $total = round($accounts->sum(fn (LedgerAccount $account): float => (float) $account->postedBalance()), 2);

        return '<div class="mt-4 rounded-xl bg-white px-6 py-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">'
            .'<div class="flex items-center justify-between gap-4">'
            .'<div>'
            .'<div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total vendor payables</div>'
            .'<div class="text-xs text-gray-400 dark:text-gray-500">Sum of all vendor accounts in Parties</div>'
            .'</div>'
            .'<div class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">'
            .e(number_format($total, 2)).' Cr'
            .'</div>'
            .'</div>'
            .'</div>';
    }
}
