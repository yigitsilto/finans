<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    public function test_tests_run_against_a_supported_database(): void
    {
        $this->assertContains(DB::connection()->getDriverName(), ['pgsql', 'mariadb']);
        $this->assertSame('finans_test', DB::connection()->getDatabaseName());
    }

    public function test_numeric_column_keeps_exact_precision(): void
    {
        // Float ile 0.1 + 0.2 != 0.3; NUMERIC tam sonuç vermelidir.
        $row = DB::selectOne('select CAST(CAST(0.1 AS DECIMAL(18,2)) + CAST(0.2 AS DECIMAL(18,2)) AS DECIMAL(18,2)) as total');

        $this->assertSame('0.30', $row->total);
    }

    public function test_database_uses_utf8_for_turkish_characters(): void
    {
        $row = DB::selectOne("select 'ĞÜŞİÖÇ ğüşıöç' as value");

        $this->assertSame('ĞÜŞİÖÇ ğüşıöç', $row->value);
    }
}
