<?php

namespace App\Actions;

use App\Enums\CheckoutStatus;
use App\Exceptions\EmptyBasket;
use App\Exceptions\TabAgreementRequired;
use App\Exceptions\TabLimitExceeded;
use App\Models\Checkout;
use App\Models\Tab;
use Illuminate\Support\Facades\DB;

class RequestTrustTabCheckout
{
    /**
     * Put the whole basket on the tab. Stock stays until the customer confirms
     * the basket. Nothing hits MoMo.
     *
     * @throws EmptyBasket
     * @throws TabAgreementRequired
     * @throws TabLimitExceeded
     */
    public function handle(Checkout $checkout): Checkout
    {
        return DB::transaction(function () use ($checkout): Checkout {
            $locked = Checkout::query()->whereKey($checkout->getKey())->lockForUpdate()->firstOrFail();
            $tab = Tab::query()->whereKey($locked->tab_id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('lines');

            if ($locked->isAwaitingConfirmation()) {
                return $locked;
            }

            if (! $locked->isOpen()) {
                throw new \RuntimeException("Checkout {$locked->id} cannot go on the tab.");
            }

            $total = $locked->total();

            if (bccomp($total, '0.00', 2) !== 1 || $locked->lines->isEmpty()) {
                throw new EmptyBasket;
            }

            if (! $tab->hasAcceptedAgreement()) {
                throw new TabAgreementRequired;
            }

            if ($tab->wouldExceedLimit($total)) {
                throw new TabLimitExceeded;
            }

            $locked->momo_amount = '0.00';
            $locked->tab_amount = $total;
            $locked->status = CheckoutStatus::AwaitingConfirmation;
            $locked->save();

            return $locked->fresh(['lines']);
        });
    }
}
