<?php

namespace App\Enums;

enum ChequeDirection: string
{
    case Outgoing = 'outgoing';
    case Incoming = 'incoming';

    public function label(): string
    {
        return match ($this) {
            self::Outgoing => 'Outgoing',
            self::Incoming => 'Incoming',
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
