<?php

namespace App\Actions;

use App\Enums\CheckoutStatus;
use App\Exceptions\EmptyBasket;
use App\Exceptions\InvalidHybridSplit;
use App\Exceptions\TabAgreementRequired;
use App\Exceptions\TabLimitExceeded;
use App\Models\Checkout;
use App\Models\Tab;
use Illuminate\Support\Facades\DB;

class RequestHybridCheckout
{
    /**
     * Split the basket: some MoMo now, the rest on the tab after they confirm.
     * Stock and ledger wait until BOTH the confirm and GET SUCCESSFUL land.
     *
     * @throws EmptyBasket
     * @throws InvalidHybridSplit
     * @throws TabAgreementRequired
     * @throws TabLimitExceeded
     */
    public function handle(Checkout $checkout, string $momoAmount): Checkout
    {
        return DB::transaction(function () use ($checkout, $momoAmount): Checkout {
            $locked = Checkout::query()->whereKey($checkout->getKey())->lockForUpdate()->firstOrFail();
            $tab = Tab::query()->whereKey($locked->tab_id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('lines');

            if ($locked->isAwaitingConfirmation()) {
                return $locked;
            }

            if (! $locked->isOpen()) {
                throw new \RuntimeException("Checkout {$locked->id} cannot be split.");
            }

            $total = $locked->total();

            if (bccomp($total, '0.00', 2) !== 1 || $locked->lines->isEmpty()) {
                throw new EmptyBasket;
            }

            $momo = number_format((float) $momoAmount, 2, '.', '');
            $remainder = bcsub($total, $momo, 2);

            if (bccomp($momo, '0.00', 2) !== 1
                || bccomp($remainder, '0.00', 2) !== 1
                || bccomp(bcadd($momo, $remainder, 2), $total, 2) !== 0) {
                throw new InvalidHybridSplit;
            }

            if (! $tab->hasAcceptedAgreement()) {
                throw new TabAgreementRequired;
            }

            if ($tab->wouldExceedLimit($remainder)) {
                throw new TabLimitExceeded;
            }

            $locked->momo_amount = $momo;
            $locked->tab_amount = $remainder;
            $locked->status = CheckoutStatus::AwaitingConfirmation;
            $locked->save();

            return $locked->fresh(['lines']);
        });
    }
}
