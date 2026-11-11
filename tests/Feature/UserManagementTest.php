<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['username' => 'yonetici']);
        $this->actingAs($this->admin);
    }

    private function validUser(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ayşe Yılmaz',
            'username' => 'ayse',
            'email' => 'ayse@example.com',
            'role' => 'finance',
            'password' => 'Guclu-Parola-1',
            'password_confirmation' => 'Guclu-Parola-1',
        ], $overrides);
    }

    public function test_admin_creates_user_with_hashed_password_and_audit(): void
    {
        $this->post('/users', $this->validUser())->assertRedirect('/users');

        $user = User::where('username', 'ayse')->sole();
        $this->assertSame('finance', $user->role->value);
        $this->assertTrue($user->is_active);
        $this->assertNotSame('Guclu-Parola-1', $user->password);
        $this->assertTrue(Hash::check('Guclu-Parola-1', $user->password));

        $log = AuditLog::where('action', 'created')->where('auditable_id', $user->id)->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('finance', $log->new_values['role']);
        $this->assertStringNotContainsString('Guclu-Parola-1', json_encode($log->toArray()));
        $this->assertArrayNotHasKey('password', $log->new_values);
    }

    public function test_username_is_stored_lowercase_and_unique_case_insensitively(): void
    {
        $this->post('/users', $this->validUser(['username' => 'AYSE']))->assertRedirect('/users');
        $this->assertDatabaseHas('users', ['username' => 'ayse']);

        $this->post('/users', $this->validUser(['username' => 'Ayse', 'email' => null]))
            ->assertSessionHasErrors(['username' => 'Bu Kullanıcı adı zaten kullanılıyor.']);
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->post('/users', $this->validUser(['password' => 'kisa', 'password_confirmation' => 'kisa']))
            ->assertSessionHasErrors('password');
        $this->post('/users', $this->validUser(['password' => 'sadeceharfler', 'password_confirmation' => 'sadeceharfler']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['username' => 'ayse']);
    }

    public function test_invalid_role_is_rejected(): void
    {
        $this->post('/users', $this->validUser(['role' => 'superadmin']))->assertSessionHasErrors('role');
    }

    public function test_role_change_and_deactivation_are_audited_separately(): void
    {
        $user = User::factory()->viewer()->create();

        $this->put("/users/{$user->id}", [
            'name' => $user->name, 'username' => $user->username, 'email' => $user->email,
            'role' => 'finance', 'is_active' => 0,
        ])->assertRedirect('/users');

        $user->refresh();
        $this->assertSame('finance', $user->role->value);
        $this->assertFalse($user->is_active);

        $role = AuditLog::where('action', 'role_changed')->sole();
        $this->assertSame(['role' => 'viewer'], $role->old_values);
        $this->assertSame(['role' => 'finance'], $role->new_values);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deactivated', 'auditable_id' => $user->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'updated', 'auditable_id' => $user->id]);
    }

    public function test_password_change_is_audited_without_values(): void
    {
        $user = User::factory()->create();

        $this->put("/users/{$user->id}", [
            'name' => $user->name, 'username' => $user->username, 'role' => 'viewer', 'is_active' => 1,
            'password' => 'Yeni-Parola-22', 'password_confirmation' => 'Yeni-Parola-22',
        ])->assertRedirect('/users');

        $this->assertTrue(Hash::check('Yeni-Parola-22', $user->fresh()->password));
        $log = AuditLog::where('action', 'password_changed')->sole();
        $this->assertNull($log->old_values);
        $this->assertNull($log->new_values);
        $this->assertStringNotContainsString('Yeni-Parola-22', json_encode(AuditLog::all()->toArray()));
    }

    public function test_empty_password_on_update_keeps_existing_password(): void
    {
        $user = User::factory()->create();
        $hash = $user->password;

        $this->put("/users/{$user->id}", [
            'name' => 'Yeni Ad', 'username' => $user->username, 'email' => $user->email, 'role' => 'viewer', 'is_active' => 1,
            'password' => '', 'password_confirmation' => '',
        ])->assertRedirect('/users');

        $this->assertSame($hash, $user->fresh()->password);
        $log = AuditLog::where('action', 'updated')->sole();
        $this->assertSame(['name' => 'Yeni Ad'], $log->new_values);
    }

    public function test_admin_cannot_demote_or_deactivate_self(): void
    {
        $base = ['name' => $this->admin->name, 'username' => $this->admin->username];

        $this->put("/users/{$this->admin->id}", $base + ['role' => 'viewer', 'is_active' => 1])
            ->assertSessionHasErrors('role');
        $this->put("/users/{$this->admin->id}", $base + ['role' => 'admin', 'is_active' => 0])
            ->assertSessionHasErrors('role');

        $this->admin->refresh();
        $this->assertTrue($this->admin->isAdmin());
        $this->assertTrue($this->admin->is_active);
    }

    public function test_another_admin_can_be_demoted_when_one_admin_remains(): void
    {
        $other = User::factory()->admin()->create();

        $this->put("/users/{$other->id}", [
            'name' => $other->name, 'username' => $other->username, 'role' => 'viewer', 'is_active' => 1,
        ])->assertRedirect('/users');

        $this->assertSame('viewer', $other->fresh()->role->value);
    }

    public function test_user_pages_never_render_password_hash(): void
    {
        $user = User::factory()->create();

        $this->get('/users')->assertOk()->assertSee($user->username)->assertDontSee($user->password);
        $this->get("/users/{$user->id}/edit")->assertOk()->assertDontSee($user->password);
    }

    public function test_users_can_be_filtered_and_searched(): void
    {
        User::factory()->finance()->create(['name' => 'Mehmet Öz', 'username' => 'mehmet']);
        User::factory()->viewer()->inactive()->create(['name' => 'Zeynep Kaya', 'username' => 'zeynep']);

        $this->get('/users?q=MEH')->assertSee('mehmet')->assertDontSee('zeynep');
        $this->get('/users?role=viewer')->assertSee('zeynep')->assertDontSee('mehmet');
        $this->get('/users?status=inactive')->assertSee('zeynep')->assertDontSee('mehmet');
        $this->get('/users?sort=password')->assertSessionHasErrors('sort');
    }

    public function test_users_cannot_be_deleted(): void
    {
        $user = User::factory()->create();

        $this->delete("/users/{$user->id}")->assertStatus(405);
        $this->assertModelExists($user);
    }
}
