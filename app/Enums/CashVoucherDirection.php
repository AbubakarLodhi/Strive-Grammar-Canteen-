<?php

namespace App\Enums;

enum CashVoucherDirection: string
{
    case Receiving = 'receiving';
    case Payment = 'payment';

    public function label(): string
    {
        return match ($this) {
            self::Receiving => 'Cash Receiving',
            self::Payment => 'Cash Payment',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
