<?php

namespace Database\Factories;

use App\Models\Checkout;
use App\Models\CheckoutLine;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckoutLine>
 */
class CheckoutLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checkout_id' => Checkout::factory(),
            'product_id' => Product::factory(),
            'name' => fake()->words(2, true),
            'unit_price' => fake()->randomElement(['18.00', '22.00', '35.00']),
            'quantity' => 1,
        ];
    }

    public function forCheckout(Checkout $checkout): static
    {
        return $this->state(['checkout_id' => $checkout->id]);
    }

    public function forProduct(Product $product, int $quantity = 1): static
    {
        return $this->state([
            'product_id' => $product->id,
            'name' => $product->name,
            'unit_price' => $product->selling_price,
            'quantity' => $quantity,
        ]);
    }
}
