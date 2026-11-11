<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /** Kullanıcı adı + IP başına dakikada izin verilen hatalı deneme. */
    public const MAX_ATTEMPTS = 5;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $username = Str::lower(trim($credentials['username']));
        $throttleKey = 'login:'.$username.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $audit->log(AuditService::LOGIN_FAILED, username: $username, new: ['reason' => 'throttled'], httpStatus: 429);

            throw ValidationException::withMessages([
                'username' => "Çok fazla hatalı deneme. {$seconds} saniye sonra tekrar deneyin.",
            ])->status(429);
        }

        $user = User::whereRaw('LOWER(username) = ?', [$username])->first();

        // Kullanıcı yoksa da hash kontrolü yap: yanıt süresinden kullanıcı varlığı anlaşılmasın.
        if ($user) {
            $passwordOk = Hash::check($credentials['password'], $user->password);
        } else {
            Hash::make($credentials['password']);
            $passwordOk = false;
        }

        if (! $user || ! $passwordOk || ! $user->is_active) {
            RateLimiter::hit($throttleKey, 60);
            $audit->log(
                AuditService::LOGIN_FAILED,
                subject: $user,
                username: $username,
                new: ['reason' => ! $user ? 'unknown_user' : (! $passwordOk ? 'wrong_password' : 'inactive')],
                httpStatus: 422,
            );

            // Hangi bilginin yanlış olduğu ya da hesabın pasif olduğu söylenmez.
            throw ValidationException::withMessages([
                'username' => 'Kullanıcı adı veya parola hatalı.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        Auth::login($user);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $audit->log(AuditService::LOGIN, subject: $user, user: $user, httpStatus: 302);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, AuditService $audit): RedirectResponse
    {
        if ($user = $request->user()) {
            $audit->log(AuditService::LOGOUT, subject: $user, user: $user, httpStatus: 302);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
