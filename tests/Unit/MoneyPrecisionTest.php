<?php

namespace Tests\Unit;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

class MoneyPrecisionTest extends TestCase
{
    public function test_bigdecimal_sums_without_float_error(): void
    {
        $this->assertNotSame(0.3, 0.1 + 0.2);

        $total = BigDecimal::of('0.10')->plus('0.20');

        $this->assertSame('0.30', (string) $total);
    }

    public function test_large_amounts_keep_kurus_precision(): void
    {
        $total = BigDecimal::of('9999999999999999.99')->plus('0.01');

        $this->assertSame('10000000000000000.00', (string) $total);
    }
}
