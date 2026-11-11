<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public static function nonAdminRoles(): array
    {
        return [['finance'], ['viewer']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_cannot_access_user_management(string $role): void
    {
        $actor = User::factory()->{$role}()->create();
        $target = User::factory()->viewer()->create();

        $this->actingAs($actor);
        $this->get('/users')->assertForbidden();
        $this->get('/users/create')->assertForbidden();
        $this->get("/users/{$target->id}/edit")->assertForbidden();
        $this->post('/users', [
            'name' => 'X', 'username' => 'yeni', 'role' => 'admin',
            'password' => 'Guclu-Parola-1', 'password_confirmation' => 'Guclu-Parola-1',
        ])->assertForbidden();
        $this->put("/users/{$target->id}", [
            'name' => $target->name, 'username' => $target->username, 'role' => 'admin', 'is_active' => 1,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['username' => 'yeni']);
        $this->assertSame('viewer', $target->fresh()->role->value);
    }

    public function test_viewer_cannot_escalate_own_role(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)->put("/users/{$viewer->id}", [
            'name' => $viewer->name, 'username' => $viewer->username, 'role' => 'admin', 'is_active' => 1,
        ])->assertForbidden();

        $this->assertSame('viewer', $viewer->fresh()->role->value);
    }

    public function test_403_page_is_turkish_and_hides_details(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get('/users')
            ->assertForbidden()
            ->assertSee('Bu sayfaya erişim yetkiniz yok.')
            ->assertDontSee('Illuminate');
    }

    public function test_admin_can_access_user_management(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/users')->assertOk();
    }

    public function test_menu_shows_admin_items_only_to_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/')
            ->assertSee('Kullanıcılar')->assertSee('Denetim Kayıtları');

        foreach (['finance', 'viewer'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())->get('/')
                ->assertOk()->assertDontSee('Kullanıcılar')->assertDontSee('Denetim Kayıtları');
        }
    }

    public function test_csrf_is_enforced_on_login(): void
    {
        // CSRF kontrolü 'testing' ortamında atlanır; bu test için ortamı geçici olarak değiştir.
        $this->app['env'] = 'local';
        $response = $this->post('/login', ['username' => 'a', 'password' => 'b']);

        $this->assertSame(419, $response->getStatusCode());
    }
}
