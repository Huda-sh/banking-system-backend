<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Transaction;
use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use App\Enums\Direction;
use App\Models\Account;
use App\Models\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $from = Account::inRandomOrder()->first();
        $to = Account::inRandomOrder()->first();

        return [
            'from_account_id' => $from?->id,
            'to_account_id' => $to?->id ?? $from?->id,
            'amount' => $this->faker->randomFloat(2, 1, 10000),
            'currency' => 'USD',
            'type' => TransactionType::DEPOSIT,
            'status' => TransactionStatus::PENDING,
            'direction' => $this->faker->randomElement(Direction::toArray()),
            'fee' => 0.00,
            'initiated_by' => User::inRandomOrder()->first()?->id ?? 1,
            'processed_by' => null,
            'approved_by' => null,
            'approved_at' => null,
            'description' => $this->faker->sentence(),
            'ip_address' => $this->faker->ipv4(),
            'metadata' => []
        ];
    }
}
