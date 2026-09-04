<?php

namespace Database\Factories;

use App\Enums\TabStatus;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tab>
 */
class TabFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => User::factory(),
            'customer_id' => User::factory(),
            'status' => TabStatus::Active,
            'expected_payment_date' => null,
            'limit_amount' => '500.00',
            'settlement_day' => 25,
            'agreement_accepted_at' => now(),
        ];
    }

    public function closed(): static
    {
        return $this->state(['status' => TabStatus::Closed]);
    }

    public function withoutAgreement(): static
    {
        return $this->state([
            'limit_amount' => null,
            'settlement_day' => null,
            'agreement_accepted_at' => null,
        ]);
    }

    public function proposed(string $limit = '500.00', int $day = 25): static
    {
        return $this->state([
            'limit_amount' => $limit,
            'settlement_day' => $day,
            'agreement_accepted_at' => null,
        ]);
    }

    public function agreed(string $limit = '500.00', int $day = 25): static
    {
        return $this->state([
            'limit_amount' => $limit,
            'settlement_day' => $day,
            'agreement_accepted_at' => now(),
        ]);
    }

    public function between(User $merchant, User $customer): static
    {
        return $this->state([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
        ]);
    }
}
