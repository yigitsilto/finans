<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::defaultView('partials.pagination');

        if (config('app.force_https')) {
            URL::forceScheme('https');
        }
        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        /*
        | Yetki kuralları. Route'larda `can:<yetki>` middleware'i ile backend'de uygulanır;
        | menü gizleme yalnızca kullanıcı deneyimi içindir.
        */
        Gate::define('view-finance', fn (User $user) => $user->is_active);
        Gate::define('write-finance', fn (User $user) => $user->is_active && $user->hasRole(Role::Admin, Role::Finance));
        Gate::define('manage-users', fn (User $user) => $user->is_active && $user->isAdmin());
        Gate::define('view-audit', fn (User $user) => $user->is_active && $user->isAdmin());
    }
}
