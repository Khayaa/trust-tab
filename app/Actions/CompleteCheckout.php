<?php

namespace App\Actions;

use App\Enums\CheckoutStatus;
use App\Models\Checkout;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class CompleteCheckout
{
    /**
     * Finish a paid till sale. Stock comes off the shelf only here.
     *
     * Pay Now reaches this after GET SUCCESSFUL. TrustTab and hybrid will
     * call it when their other rail is also done. Status is assigned on the
     * model, never mass-assigned.
     */
    public function handle(Checkout $checkout): Checkout
    {
        return DB::transaction(function () use ($checkout): Checkout {
            $locked = Checkout::query()->whereKey($checkout->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isCompleted()) {
                return $locked;
            }

            $lines = $locked->lines()->lockForUpdate()->get();

            foreach ($lines as $line) {
                $product = Product::query()
                    ->whereKey($line->product_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $product->stock_quantity = max(0, $product->stock_quantity - $line->quantity);
                $product->save();
            }

            $locked->status = CheckoutStatus::Completed;
            $locked->completed_at = now();
            $locked->save();

            return $locked->fresh(['lines']);
        });
    }
}
