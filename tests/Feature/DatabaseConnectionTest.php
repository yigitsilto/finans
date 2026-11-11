<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    public function test_tests_run_against_postgresql(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('finans_test', DB::connection()->getDatabaseName());
    }

    public function test_numeric_column_keeps_exact_precision(): void
    {
        // Float ile 0.1 + 0.2 != 0.3; NUMERIC tam sonuç vermelidir.
        $row = DB::selectOne('select (0.1::numeric(18,2) + 0.2::numeric(18,2))::text as total');

        $this->assertSame('0.30', $row->total);
    }

    public function test_database_uses_utf8_for_turkish_characters(): void
    {
        $row = DB::selectOne("select 'ĞÜŞİÖÇ ğüşıöç'::text as value");

        $this->assertSame('ĞÜŞİÖÇ ğüşıöç', $row->value);
    }
}
