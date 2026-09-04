<?php

namespace Database\Factories;

use App\Enums\CheckoutStatus;
use App\Models\Checkout;
use App\Models\Tab;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Checkout>
 */
class CheckoutFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tab_id' => Tab::factory(),
        ];
    }

    public function cancelled(): static
    {
        return $this->afterMaking(function (Checkout $checkout): void {
            $checkout->status = CheckoutStatus::Cancelled;
            $checkout->cancelled_at = now();
        });
    }

    public function awaitingMomo(string $amount = '75.00'): static
    {
        return $this->afterMaking(function (Checkout $checkout) use ($amount): void {
            $checkout->status = CheckoutStatus::AwaitingMomo;
            $checkout->momo_amount = $amount;
            $checkout->tab_amount = '0.00';
        });
    }

    public function hybrid(string $momoAmount = '50.00', string $tabAmount = '25.00'): static
    {
        return $this->afterMaking(function (Checkout $checkout) use ($momoAmount, $tabAmount): void {
            $checkout->status = CheckoutStatus::AwaitingConfirmation;
            $checkout->momo_amount = $momoAmount;
            $checkout->tab_amount = $tabAmount;
        });
    }

    public function awaitingConfirmation(string $amount = '75.00'): static
    {
        return $this->afterMaking(function (Checkout $checkout) use ($amount): void {
            $checkout->status = CheckoutStatus::AwaitingConfirmation;
            $checkout->momo_amount = '0.00';
            $checkout->tab_amount = $amount;
        });
    }

    public function completed(string $amount = '75.00'): static
    {
        return $this->afterMaking(function (Checkout $checkout) use ($amount): void {
            $checkout->status = CheckoutStatus::Completed;
            $checkout->momo_amount = $amount;
            $checkout->tab_amount = '0.00';
            $checkout->completed_at = now();
        });
    }

    public function forTab(Tab $tab): static
    {
        return $this->state(['tab_id' => $tab->id]);
    }
}
