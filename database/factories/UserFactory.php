<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Testlerde kullanılan ortak parola (yalnızca test ortamı). */
    public const TEST_PASSWORD = 'Test-Parola-123';

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make(self::TEST_PASSWORD),
            'role' => Role::Viewer,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => Role::Admin]);
    }

    public function finance(): static
    {
        return $this->state(['role' => Role::Finance]);
    }

    public function viewer(): static
    {
        return $this->state(['role' => Role::Viewer]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
