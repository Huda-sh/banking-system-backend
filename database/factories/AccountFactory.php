<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Account;
use App\Models\AccountType;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_type_id' => AccountType::inRandomOrder()->first()?->id ?? 1,
            'parent_account_id' => null,
            'account_number' => $this->faker->unique()->numerify('##########'),
            'balance' => $this->faker->randomFloat(2, 0, 10000),
            'currency' => 'USD',
        ];
    }
}
