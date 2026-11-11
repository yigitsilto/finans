<?php

namespace App\Enums;

enum TransactionType: string
{
    case Opening = 'opening';
    case Inflow = 'inflow';
    case Outflow = 'outflow';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Açılış bakiyesi',
            self::Inflow => 'Nakit girişi',
            self::Outflow => 'Nakit çıkışı',
            self::Transfer => 'Transfer',
        };
    }
}
