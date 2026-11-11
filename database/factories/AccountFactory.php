<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'type' => AccountType::Bank,
            'name' => 'Hesap '.fake()->unique()->numerify('####'),
            'bank_name' => 'Test Bankası',
            'currency' => 'TRY',
            'allow_negative' => false,
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function cash(): static
    {
        return $this->state(['type' => AccountType::Cash, 'bank_name' => null]);
    }

    public function bank(): static
    {
        return $this->state(['type' => AccountType::Bank]);
    }

    public function pos(?Account $settlement = null): static
    {
        return $this->state(fn () => [
            'type' => AccountType::Pos,
            'settlement_account_id' => $settlement?->id ?? Account::factory()->bank(),
        ]);
    }
}
