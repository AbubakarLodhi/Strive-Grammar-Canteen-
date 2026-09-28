<?php

namespace App\Enums;

enum ChequeStatus: string
{
    case Pending = 'pending';
    case Cleared = 'cleared';
    case Bounced = 'bounced';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Cleared => 'Cleared',
            self::Bounced => 'Bounced',
            self::Cancelled => 'Cancelled',
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
