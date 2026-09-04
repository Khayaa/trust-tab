<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => User::factory()->merchant(),
            'name' => fake()->unique()->words(3, true),
            'barcode' => fake()->unique()->numerify('600##########'),
            'selling_price' => fake()->randomElement(['18.00', '22.00', '35.00', '50.00', '75.00', '110.00']),
            'stock_quantity' => fake()->numberBetween(5, 80),
            'low_stock_threshold' => 8,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function forMerchant(User $merchant): static
    {
        return $this->state(['merchant_id' => $merchant->id]);
    }
}
