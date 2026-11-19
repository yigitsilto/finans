<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashflowService;
use App\Services\FinancialAnalysisService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.demo' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00'));
    }

    public function test_demo_data_loads_through_services_and_reconciles(): void
    {
        User::factory()->admin()->create();

        $this->artisan('finans:demo-data')->assertSuccessful();

        $this->assertGreaterThan(100, Transaction::count());
        $a = app(FinancialAnalysisService::class)->analyze();
        $this->assertTrue($a['month']['reconciled']);
        foreach ($a['aging']['buckets'] as $key => $bucket) {
            $this->assertNotSame('0.00', $bucket['amount'], "{$key} dilimi dolu olmalı");
        }
        $series = app(CashflowService::class)->series(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::today(), 'month');
        $this->assertTrue($series['carry_over_ok']);
    }

    public function test_demo_data_refuses_non_empty_system_and_requires_admin(): void
    {
        $this->artisan('finans:demo-data')->assertFailed();

        User::factory()->admin()->create();
        Account::factory()->create();
        $this->artisan('finans:demo-data')->assertFailed();
        $this->assertSame(0, Transaction::count());
    }

    public function test_demo_users_are_created_with_random_passwords(): void
    {
        User::factory()->admin()->create();

        $this->artisan('finans:demo-data --with-users')->assertSuccessful();

        $this->assertSame('finance', User::where('username', 'demo.finans')->sole()->role->value);
        $this->assertSame('viewer', User::where('username', 'demo.izleyici')->sole()->role->value);
    }

    public function test_demo_banner_and_security_headers(): void
    {
        $this->get('/login')
            ->assertSee('Demo ortamı')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        config(['app.demo' => false]);
        $this->get('/login')->assertDontSee('Demo ortamı')->assertHeaderMissing('X-Robots-Tag');
    }
}
