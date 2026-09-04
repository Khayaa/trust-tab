<?php

namespace Database\Factories;

use App\Models\MomoWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MomoWebhookEvent>
 */
class MomoWebhookEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference_id' => Str::uuid()->toString(),
            'event_type' => 'requesttopay',
            'payload' => ['status' => 'SUCCESSFUL'],
            'processed_at' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(['processed_at' => now()]);
    }
}
