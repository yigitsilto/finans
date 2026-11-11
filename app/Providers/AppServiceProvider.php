<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
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
