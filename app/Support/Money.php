<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * TL tutarlarının okunması ve gösterimi. Float hiçbir aşamada kullanılmaz.
 */
final class Money
{
    public const SCALE = 2;

    /** NUMERIC(18,2) üst sınırı. */
    public const MAX = '9999999999999999.99';

    /**
     * Kullanıcı girdisini (ör. "27.000,50", "27000,50", "27000.50") 2 basamaklı string'e çevirir.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string|int|BigDecimal $input): string
    {
        if ($input instanceof BigDecimal || is_int($input)) {
            $decimal = BigDecimal::of($input);
        } else {
            $value = str_replace([' ', "\u{00A0}", '₺', 'TL'], '', trim($input));

            if (str_contains($value, ',')) {
                // Türkçe biçim: nokta binlik ayırıcı, virgül ondalık.
                if (! preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$|^-?\d+(,\d+)?$/', $value)) {
                    throw new InvalidArgumentException('Geçersiz tutar biçimi.');
                }
                $value = str_replace(['.', ','], ['', '.'], $value);
            } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $value)) {
                // "27.000" gibi yalnızca binlik ayırıcı içeren değer.
                $value = str_replace('.', '', $value);
            } elseif (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
                throw new InvalidArgumentException('Geçersiz tutar biçimi.');
            }

            $decimal = BigDecimal::of($value);
        }

        if ($decimal->getScale() > self::SCALE && ! $decimal->toScale(self::SCALE, RoundingMode::DOWN)->isEqualTo($decimal)) {
            throw new InvalidArgumentException('Tutar en fazla 2 ondalık basamak içerebilir.');
        }

        $decimal = $decimal->toScale(self::SCALE, RoundingMode::UNNECESSARY);

        if ($decimal->abs()->isGreaterThan(self::MAX)) {
            throw new InvalidArgumentException('Tutar izin verilen üst sınırı aşıyor.');
        }

        return (string) $decimal;
    }

    /** "1234567.5" -> "1.234.567,50 ₺" */
    public static function format(string|int|BigDecimal|null $amount, bool $symbol = true): string
    {
        $decimal = BigDecimal::of($amount ?? '0')->toScale(self::SCALE, RoundingMode::HALF_UP);
        $negative = $decimal->isNegative();
        [$int, $frac] = explode('.', (string) $decimal->abs());

        $grouped = ltrim(strrev(implode('.', str_split(strrev($int), 3))), '.');
        $text = ($negative ? '-' : '').$grouped.','.$frac;

        return $symbol ? $text.' ₺' : $text;
    }

    public static function of(string|int|BigDecimal|null $amount): BigDecimal
    {
        return BigDecimal::of($amount ?? '0')->toScale(self::SCALE, RoundingMode::UNNECESSARY);
    }
}
