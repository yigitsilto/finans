<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\LoginController;
use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login:ahmet|127.0.0.1');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Kullanıcı adı')->assertSee('name="_token"', false);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/users')->assertRedirect('/login');
    }

    public static function roles(): array
    {
        return [['admin'], ['finance'], ['viewer']];
    }

    #[DataProvider('roles')]
    public function test_each_role_can_log_in(string $role): void
    {
        $user = User::factory()->{$role}()->create(['username' => 'ahmet']);

        $this->post('/login', ['username' => 'ahmet', 'password' => UserFactory::TEST_PASSWORD])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'user_id' => $user->id]);
    }

    public function test_username_is_case_insensitive(): void
    {
        $user = User::factory()->create(['username' => 'ahmet']);

        $this->post('/login', ['username' => '  AHMET ', 'password' => UserFactory::TEST_PASSWORD]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_session_id_is_regenerated_on_login(): void
    {
        User::factory()->create(['username' => 'ahmet']);
        $this->startSession();
        $before = session()->getId();

        $this->post('/login', ['username' => 'ahmet', 'password' => UserFactory::TEST_PASSWORD, '_token' => csrf_token()]);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_wrong_password_fails_and_is_audited_without_the_password(): void
    {
        $user = User::factory()->create(['username' => 'ahmet']);

        $this->from('/login')
            ->post('/login', ['username' => 'ahmet', 'password' => 'yanlis-parola-999'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['username' => 'Kullanıcı adı veya parola hatalı.']);

        $this->assertGuest();
        $log = AuditLog::where('action', 'login_failed')->sole();
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertSame('wrong_password', $log->new_values['reason']);
        $this->assertStringNotContainsString('yanlis-parola-999', json_encode($log->toArray()));
    }

    public function test_unknown_user_gets_same_message_as_wrong_password(): void
    {
        $this->post('/login', ['username' => 'olmayan', 'password' => 'bir-parola-123'])
            ->assertSessionHasErrors(['username' => 'Kullanıcı adı veya parola hatalı.']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'login_failed', 'username' => 'olmayan', 'user_id' => null]);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['username' => 'ahmet']);

        $this->post('/login', ['username' => 'ahmet', 'password' => UserFactory::TEST_PASSWORD])
            ->assertSessionHasErrors(['username' => 'Kullanıcı adı veya parola hatalı.']);

        $this->assertGuest();
        $this->assertSame('inactive', AuditLog::where('action', 'login_failed')->sole()->new_values['reason']);
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        User::factory()->create(['username' => 'ahmet']);

        for ($i = 0; $i < LoginController::MAX_ATTEMPTS; $i++) {
            $this->post('/login', ['username' => 'ahmet', 'password' => 'yanlis-'.$i]);
        }

        // Doğru parola bile kilit süresince reddedilir.
        $this->post('/login', ['username' => 'ahmet', 'password' => UserFactory::TEST_PASSWORD])
            ->assertSessionHasErrors(['username' => 'Çok fazla hatalı deneme. 60 saniye sonra tekrar deneyin.']);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'login_failed', 'http_status' => 429]);
    }

    public function test_logout_ends_session_and_is_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'logout', 'user_id' => $user->id]);
    }

    public function test_user_deactivated_during_session_is_logged_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }
}
