<?php

namespace Database\Factories;

use App\Enums\TabEntryStatus;
use App\Models\Tab;
use App\Models\TabEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TabEntry>
 */
class TabEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tab_id' => Tab::factory(),
            'created_by' => fn (array $attributes) => Tab::find($attributes['tab_id'])->merchant_id,
            'description' => fake()->randomElement(['Bread and milk', 'Airtime', 'Toiletries', 'Groceries']),
            'amount' => fake()->randomFloat(2, 5, 300),
            'note' => null,
            'status' => TabEntryStatus::PendingConfirmation,
        ];
    }

    public function confirmed(): static
    {
        return $this->state([
            'status' => TabEntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function disputed(): static
    {
        return $this->state([
            'status' => TabEntryStatus::Disputed,
            'disputed_at' => now(),
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state([
            'status' => TabEntryStatus::Withdrawn,
            'disputed_at' => now()->subMinute(),
            'withdrawn_at' => now(),
        ]);
    }

    public function settled(): static
    {
        return $this->state([
            'status' => TabEntryStatus::Settled,
            'confirmed_at' => now()->subMinute(),
            'settled_at' => now(),
        ]);
    }

    public function amount(float|string $amount): static
    {
        return $this->state(['amount' => $amount]);
    }
}
