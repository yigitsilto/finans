<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL ve MariaDB'de aynı sonucu veren SQL parçaları.
 * Uygulama geliştirmede PostgreSQL, cPanel sunucusunda MariaDB 10.11 ile çalışır.
 *
 * Kurallar:
 * - Para toplamları her zaman CAST(... AS DECIMAL(18,2)) ile döner; iki sürücüde de PDO
 *   bunu "1234.50" biçiminde string verir (float yok).
 * - FILTER (WHERE ...) yerine SUM(CASE WHEN ... END) kullanılır.
 */
final class Sql
{
    public static function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /** Tutar ifadesini 2 basamaklı ondalığa çevirir. */
    public static function money(string $expression): string
    {
        return "CAST({$expression} AS DECIMAL(18,2))";
    }

    /** SUM(expr) — boşsa 0, 2 basamaklı. */
    public static function sum(string $expression, ?string $when = null): string
    {
        $inner = $when === null ? $expression : "CASE WHEN {$when} THEN {$expression} END";

        return self::money("COALESCE(SUM({$inner}), 0)");
    }

    /** İki tarih arasındaki gün farkı: $a − $b (tam sayı). */
    public static function daysBetween(string $a, string $b): string
    {
        return self::isPostgres() ? "({$a} - {$b})" : "DATEDIFF({$a}, {$b})";
    }

    /** Tarihin "YYYY-MM" metni. */
    public static function yearMonth(string $column): string
    {
        return self::isPostgres() ? "to_char({$column}, 'YYYY-MM')" : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    /** Parametreyi tarih olarak bağlamak için. */
    public static function dateParam(): string
    {
        return 'CAST(? AS DATE)';
    }
}
