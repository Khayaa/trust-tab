<?php

namespace App\Actions;

use App\Enums\CheckoutStatus;
use App\Models\Checkout;
use Illuminate\Support\Facades\DB;

class ReopenCheckout
{
    /**
     * MoMo did not take the money. Put the basket back so they can retry.
     * Stock was never taken.
     */
    public function handle(Checkout $checkout): Checkout
    {
        return DB::transaction(function () use ($checkout): Checkout {
            $locked = Checkout::query()->whereKey($checkout->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isAwaitingMomo()) {
                return $locked;
            }

            $locked->status = CheckoutStatus::Open;
            $locked->save();

            return $locked->fresh(['lines']);
        });
    }
}
