<?php

namespace App\Filament\Widgets;

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use Filament\Widgets\Widget;

class BankLedgerStatsWidget extends Widget
{
    protected string $view = 'filament.widgets.bank-ledger-stats-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 0;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $merchantId = FinanceAccess::merchantId();

        if (! $merchantId) {
            return [
                'cashBalance' => '0.00',
                'banks' => [],
            ];
        }

        $merchant = Merchant::query()->find($merchantId);

        if ($merchant) {
            app(FinanceLedger::class)->provisionDefaultAccounts($merchant);
        }

        $cash = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('code', FinanceLedger::CASH_ACCOUNT_CODE)
            ->first();

        $banks = LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('is_bank', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (LedgerAccount $account): array => [
                'name' => $account->name,
                'account_number' => $account->account_number,
                'balance' => $account->postedBalance(),
            ])
            ->all();

        return [
            'cashBalance' => $cash?->postedBalance() ?? '0.00',
            'banks' => $banks,
        ];
    }
}
