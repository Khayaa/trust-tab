<?php

namespace App\Actions;

use App\Enums\CheckoutStatus;
use App\Models\Checkout;
use Illuminate\Support\Facades\DB;

class CancelOpenCheckout
{
    /**
     * Drop the open till basket. Stock was never taken, so there is nothing to put back.
     */
    public function handle(Checkout $checkout): Checkout
    {
        return DB::transaction(function () use ($checkout): Checkout {
            $locked = Checkout::query()->whereKey($checkout->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen() && ! $locked->isAwaitingConfirmation()) {
                return $locked;
            }

            $locked->status = CheckoutStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->save();

            return $locked->fresh(['lines']);
        });
    }
}
