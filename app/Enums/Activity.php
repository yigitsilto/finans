<?php

namespace App\Enums;

/** Nakit akışı faaliyet sınıfı (gelir/gider ile karıştırılmamalı). */
enum Activity: string
{
    case Operating = 'operating';
    case Financing = 'financing';

    public function label(): string
    {
        return match ($this) {
            self::Operating => 'İşletme faaliyeti',
            self::Financing => 'Finansman faaliyeti',
        };
    }
}
