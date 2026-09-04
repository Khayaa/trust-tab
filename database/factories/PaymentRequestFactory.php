<?php

namespace Database\Factories;

use App\Enums\PaymentRequestStatus;
use App\Models\PaymentRequest;
use App\Models\Tab;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentRequest>
 */
class PaymentRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tab_id' => Tab::factory(),
            'reference_id' => Str::uuid()->toString(),
            'external_reference' => null,
            'amount' => fake()->randomFloat(2, 10, 500),
            'unallocated_amount' => '0.00',
            'currency' => config('services.momo.currency', 'EUR'),
            'payer_msisdn' => '46733123499',
            'status' => PaymentRequestStatus::Created,
            'requested_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state([
            'status' => PaymentRequestStatus::Pending,
            'requested_at' => now(),
        ]);
    }

    public function successful(): static
    {
        return $this->state([
            'status' => PaymentRequestStatus::Successful,
            'requested_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    public function failed(?string $reason = null): static
    {
        return $this->state([
            'status' => PaymentRequestStatus::Failed,
            'requested_at' => now()->subMinute(),
            'completed_at' => now(),
            'failure_reason' => $reason ?? 'PAYER_REJECTED',
        ]);
    }

    /**
     * Payer numbers the MoMo sandbox maps to a forced outcome.
     *
     * @param  'failed'|'rejected'|'timeout'|'pending'  $outcome
     */
    public function payerForcing(string $outcome): static
    {
        return $this->state(['payer_msisdn' => match ($outcome) {
            'failed' => '46733123450',
            'rejected' => '46733123451',
            'timeout' => '46733123452',
            'pending' => '46733123454',
        }]);
    }
}
