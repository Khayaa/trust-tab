<?php

namespace App\Actions;

use App\Enums\CheckoutStatus;
use App\Exceptions\CheckoutAwaitingConfirmation;
use App\Exceptions\CheckoutAwaitingMomo;
use App\Exceptions\NotEnoughStock;
use App\Exceptions\ProductNotOnShelf;
use App\Models\Checkout;
use App\Models\Product;
use App\Models\Tab;
use Illuminate\Support\Facades\DB;

class AddProductToBasket
{
    /**
     * Put one more of this product on the open till for this tab.
     *
     * Reuses the existing OPEN checkout so a refresh does not start a second
     * basket. The line keeps the price from the first tap. Stock stays on the
     * shelf until checkout completes.
     */
    public function handle(Tab $tab, Product $product): Checkout
    {
        return DB::transaction(function () use ($tab, $product): Checkout {
            $lockedTab = Tab::query()->whereKey($tab->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedTab->checkouts()->where('status', CheckoutStatus::AwaitingMomo)->lockForUpdate()->exists()) {
                throw new CheckoutAwaitingMomo;
            }

            if ($lockedTab->checkouts()->where('status', CheckoutStatus::AwaitingConfirmation)->lockForUpdate()->exists()) {
                throw new CheckoutAwaitingConfirmation;
            }

            $lockedProduct = Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedProduct->merchant_id !== $lockedTab->merchant_id || ! $lockedProduct->is_active) {
                throw new ProductNotOnShelf;
            }

            $checkout = $lockedTab->checkouts()->open()->lockForUpdate()->first();

            if ($checkout === null) {
                $checkout = $lockedTab->checkouts()->make();
                $checkout->status = CheckoutStatus::Open;
                $checkout->save();
            }

            $line = $checkout->lines()->where('product_id', $lockedProduct->id)->lockForUpdate()->first();
            $nextQuantity = ($line?->quantity ?? 0) + 1;

            if ($nextQuantity > $lockedProduct->stock_quantity) {
                throw new NotEnoughStock($lockedProduct->stock_quantity);
            }

            if ($line === null) {
                $checkout->lines()->create([
                    'product_id' => $lockedProduct->id,
                    'name' => $lockedProduct->name,
                    'unit_price' => $lockedProduct->selling_price,
                    'quantity' => 1,
                ]);
            } else {
                $line->quantity = $nextQuantity;
                $line->save();
            }

            return $checkout->fresh(['lines']);
        });
    }
}
