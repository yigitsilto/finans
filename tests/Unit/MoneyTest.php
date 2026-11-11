<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function validInputs(): array
    {
        return [
            'tr binlik + ondalık' => ['27.000,50', '27000.50'],
            'tr ondalık' => ['27000,5', '27000.50'],
            'nokta ondalık' => ['27000.50', '27000.50'],
            'yalnızca binlik' => ['1.700', '1700.00'],
            'milyon' => ['1.234.567,89', '1234567.89'],
            'tam sayı' => ['42', '42.00'],
            'sembollü' => ['1.500,00 ₺', '1500.00'],
            'kuruş' => ['0,01', '0.01'],
            'sondaki sıfırlar' => ['10,500', '10.50'],
            'negatif' => ['-5,25', '-5.25'],
            'üst sınır' => ['9999999999999999.99', '9999999999999999.99'],
        ];
    }

    #[DataProvider('validInputs')]
    public function test_parse_valid_inputs(string $input, string $expected): void
    {
        $this->assertSame($expected, Money::parse($input));
    }

    public static function invalidInputs(): array
    {
        return [
            'üç ondalık' => ['10,555'],
            'harf' => ['abc'],
            'boş' => [''],
            'çift virgül' => ['1,2,3'],
            'yanlış gruplama' => ['12.34,56'],
            'üst sınır aşımı' => ['10000000000000000.00'],
            'bilimsel gösterim' => ['1e5'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_parse_rejects_invalid_inputs(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse($input);
    }

    public function test_format_uses_turkish_separators(): void
    {
        $this->assertSame('27.000,50 ₺', Money::format('27000.5'));
        $this->assertSame('1.234.567,89 ₺', Money::format('1234567.89'));
        $this->assertSame('0,00 ₺', Money::format(null));
        $this->assertSame('-1.700,00', Money::format('-1700', false));
        $this->assertSame('999,00 ₺', Money::format('999'));
    }
}
