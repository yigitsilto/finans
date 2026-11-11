<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * İlk Admin kullanıcısını oluşturur. Parola komut satırı argümanı olarak alınmaz
 * (shell geçmişine düşmesin diye), gizli olarak sorulur.
 */
class CreateAdmin extends Command
{
    protected $signature = 'finans:create-admin {username : Kullanıcı adı} {--name= : Ad soyad}';

    protected $description = 'Yeni bir Admin kullanıcısı oluşturur';

    public function handle(AuditService $audit): int
    {
        $username = Str::lower(trim($this->argument('username')));
        $name = $this->option('name') ?: $this->ask('Ad soyad');
        $password = $this->secret('Parola (en az 10 karakter, harf ve rakam)');
        $confirmation = $this->secret('Parola (tekrar)');

        $validator = Validator::make(
            ['username' => $username, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'username' => ['required', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
                'name' => ['required', 'string', 'max:100'],
                'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'username' => $username, 'password' => $password]);
        $user->role = Role::Admin;
        $user->is_active = true;
        $user->save();

        $audit->log(AuditService::CREATED, $user, new: $user->only(['name', 'username', 'role', 'is_active']));

        $this->info("Admin kullanıcısı oluşturuldu: {$username}");

        return self::SUCCESS;
    }
}
