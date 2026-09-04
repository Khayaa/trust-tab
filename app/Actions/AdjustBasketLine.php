<?php

namespace App\Actions;

use App\Exceptions\NotEnoughStock;
use App\Models\Checkout;
use App\Models\CheckoutLine;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdjustBasketLine
{
    /**
     * Set a line's quantity. Zero removes it. Stock is not moved.
     */
    public function handle(CheckoutLine $line, int $quantity): Checkout
    {
        return DB::transaction(function () use ($line, $quantity): Checkout {
            $locked = CheckoutLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
            $checkout = Checkout::query()->whereKey($locked->checkout_id)->lockForUpdate()->firstOrFail();

            if (! $checkout->isOpen()) {
                throw new RuntimeException("Checkout {$checkout->id} is not open.");
            }

            $quantity = max(0, $quantity);

            if ($quantity === 0) {
                $locked->delete();

                return $checkout->fresh(['lines']);
            }

            $product = Product::query()
                ->whereKey($locked->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($quantity > $product->stock_quantity) {
                throw new NotEnoughStock($product->stock_quantity);
            }

            $locked->quantity = $quantity;
            $locked->save();

            return $checkout->fresh(['lines']);
        });
    }
}
