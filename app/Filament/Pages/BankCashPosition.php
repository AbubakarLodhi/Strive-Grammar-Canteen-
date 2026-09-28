<?php

namespace App\Filament\Pages;

use App\Models\LedgerAccount;
use App\Services\Finance\FinanceLedger;
use App\Support\FinanceAccess;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class BankCashPosition extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::BuildingLibrary;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 38;

    protected static ?string $title = 'Bank & Cash Position';

    protected static ?string $navigationLabel = 'Bank & Cash Position';

    protected string $view = 'filament.pages.bank-cash-position';

    public static function canAccess(): bool
    {
        return FinanceAccess::can('bank_position');
    }

    /**
     * @return list<array{name: string, balance: string, is_cash: bool}>
     */
    public function getAccounts(): array
    {
        $merchantId = FinanceAccess::merchantId();
        if (! $merchantId) {
            return [];
        }

        return LedgerAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->where('code', FinanceLedger::CASH_ACCOUNT_CODE)->orWhere('is_bank', true);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [FinanceLedger::CASH_ACCOUNT_CODE])
            ->orderBy('name')
            ->get()
            ->map(fn (LedgerAccount $account): array => [
                'name' => $account->is_bank ? $account->bankLabel() : $account->name,
                'balance' => $account->postedBalance(),
                'is_cash' => $account->code === FinanceLedger::CASH_ACCOUNT_CODE,
            ])
            ->all();
    }
}
