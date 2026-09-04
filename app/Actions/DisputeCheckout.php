<?php

namespace App\Actions;

use App\Enums\CheckoutStatus;
use App\Models\Checkout;
use Illuminate\Support\Facades\DB;

class DisputeCheckout
{
    /**
     * The customer rejects the basket. Stock was never taken. The till opens
     * again so the merchant can fix the lines.
     */
    public function handle(Checkout $checkout): Checkout
    {
        return DB::transaction(function () use ($checkout): Checkout {
            $locked = Checkout::query()->whereKey($checkout->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isAwaitingConfirmation()) {
                return $locked;
            }

            $locked->status = CheckoutStatus::Open;
            $locked->save();

            return $locked->fresh(['lines']);
        });
    }
}
