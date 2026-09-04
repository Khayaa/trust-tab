<?php

namespace Database\Factories;

use App\Models\PaymentRequest;
use App\Models\PaymentRequestEntry;
use App\Models\TabEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentRequestEntry>
 */
class PaymentRequestEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_request_id' => PaymentRequest::factory(),
            'tab_entry_id' => TabEntry::factory()->confirmed(),
            'amount' => fake()->randomFloat(2, 5, 300),
        ];
    }
}
