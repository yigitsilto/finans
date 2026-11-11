<?php

namespace App\Enums;

enum AccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Pos = 'pos';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Kasa',
            self::Bank => 'Banka',
            self::Pos => 'POS / Blokeli',
        };
    }

    /** Kullanılabilir (serbest) nakit mi? Blokeli POS değildir. */
    public function isAvailableCash(): bool
    {
        return $this !== self::Pos;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(fn ($c) => ['v' => $c->value, 'l' => $c->label()], self::cases()), 'l', 'v');
    }
}
