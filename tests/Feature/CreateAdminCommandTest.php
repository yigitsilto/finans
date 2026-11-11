<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_active_admin(): void
    {
        $this->artisan('finans:create-admin', ['username' => 'Patron', '--name' => 'Patron Kişi'])
            ->expectsQuestion('Parola (en az 10 karakter, harf ve rakam)', 'Guclu-Parola-9')
            ->expectsQuestion('Parola (tekrar)', 'Guclu-Parola-9')
            ->assertSuccessful();

        $user = User::where('username', 'patron')->sole();
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'auditable_id' => $user->id]);
    }

    public function test_command_rejects_weak_or_mismatched_password(): void
    {
        $this->artisan('finans:create-admin', ['username' => 'patron', '--name' => 'X'])
            ->expectsQuestion('Parola (en az 10 karakter, harf ve rakam)', 'kisa')
            ->expectsQuestion('Parola (tekrar)', 'baska')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['username' => 'patron']);
    }
}
