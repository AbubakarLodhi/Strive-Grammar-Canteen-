<?php

namespace App\Filament\Pages;

use App\Support\FinanceAccess;
use Filament\Support\Icons\Heroicon;

/**
 * Bank statement report — same cash-book engine, banks preferred in nav label.
 */
class BankStatement extends CashBook
{
    protected static ?string $title = 'Bank Statement';

    protected static ?string $navigationLabel = 'Bank Statement';

    protected static ?int $navigationSort = 39;

    protected string $view = 'filament.pages.cash-book';

    public static function canAccess(): bool
    {
        return FinanceAccess::can('bank_statements');
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return Heroicon::DocumentText;
    }
}
