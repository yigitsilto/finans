<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_reports_ok_when_database_is_reachable(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'database' => 'ok']);
    }

    public function test_health_endpoint_returns_503_without_leaking_details_when_database_is_down(): void
    {
        config(['database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');

        $response = $this->getJson('/health');

        $response->assertStatus(503)->assertJson(['status' => 'error', 'database' => 'error']);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }
}
