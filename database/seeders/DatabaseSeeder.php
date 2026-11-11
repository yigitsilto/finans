<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Yalnızca geliştirme ortamı için örnek kullanıcılar (admin, finans, izleyici).
 * Parola .env içindeki DEV_SEED_PASSWORD değerinden alınır; kodda parola yoktur.
 * Production'da ilk Admin için: php artisan finans:create-admin <kullanici>
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Örnek kullanıcılar production ortamına yüklenemez.');
        }

        $password = env('DEV_SEED_PASSWORD');
        if (! $password) {
            throw new RuntimeException('DEV_SEED_PASSWORD .env içinde tanımlı değil.');
        }

        foreach ([
            ['admin', 'Örnek Admin', Role::Admin],
            ['finans', 'Örnek Finans', Role::Finance],
            ['izleyici', 'Örnek İzleyici', Role::Viewer],
        ] as [$username, $name, $role]) {
            $user = User::firstOrNew(['username' => $username]);
            $user->fill(['name' => $name, 'password' => $password]);
            $user->role = $role;
            $user->is_active = true;
            $user->save();
        }
    }
}
