<?php

namespace Database\Factories;

use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'msisdn' => static::sandboxSafeMsisdn(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * An MSISDN outside the 4673312345x block the MoMo sandbox reserves for
     * forced failures, so factory users pay successfully by default.
     */
    protected static function sandboxSafeMsisdn(): string
    {
        return '4673312'.fake()->unique()->numberBetween(4000, 9999);
    }

    /**
     * Give the user a merchant profile so they can own tabs.
     */
    public function merchant(?string $businessName = null): static
    {
        return $this->has(
            MerchantProfile::factory()->state(array_filter(['business_name' => $businessName])),
            'merchantProfile'
        );
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
