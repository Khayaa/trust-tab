<?php

namespace App\Actions;

use App\Exceptions\MomoRequestFailed;
use App\Exceptions\TabAgreementRequired;
use App\Exceptions\TabLimitExceeded;
use App\Models\Checkout;
use App\Models\Tab;
use Illuminate\Support\Facades\DB;

class ConfirmCheckout
{
    public function __construct(
        protected CompleteCheckout $completeCheckout,
        protected RecordCheckoutOnTab $recordCheckoutOnTab,
        protected RequestCheckoutPayNow $requestCheckoutPayNow,
    ) {}

    /**
     * The customer accepts the basket. TrustTab-only writes the ledger and
     * takes stock here. Hybrid only starts MoMo; the remainder waits for GET
     * SUCCESSFUL so a failed prompt cannot put goods on the tab.
     *
     * @throws TabAgreementRequired
     * @throws TabLimitExceeded
     * @throws MomoRequestFailed
     */
    public function handle(Checkout $checkout): Checkout
    {
        $locked = DB::transaction(function () use ($checkout): Checkout {
            $locked = Checkout::query()->whereKey($checkout->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isCompleted()) {
                return $locked->fresh(['lines']);
            }

            if ($locked->isAwaitingMomo() && $locked->isHybrid()) {
                return $locked->fresh(['lines']);
            }

            if (! $locked->isAwaitingConfirmation()) {
                throw new \RuntimeException("Checkout {$locked->id} is not waiting for confirmation.");
            }

            $tab = Tab::query()->whereKey($locked->tab_id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('lines');

            if (! $tab->hasAcceptedAgreement()) {
                throw new TabAgreementRequired;
            }

            if ($tab->limit_amount === null
                || bccomp($tab->committedAmount(), $tab->limit_amount, 2) === 1) {
                throw new TabLimitExceeded;
            }

            if ($locked->isHybrid()) {
                return $locked;
            }

            $this->recordCheckoutOnTab->handle($locked);

            return $this->completeCheckout->handle($locked);
        });

        if ($locked->isAwaitingConfirmation() && $locked->isHybrid()) {
            $this->requestCheckoutPayNow->handle($locked);

            return $locked->fresh(['lines']);
        }

        return $locked;
    }
}
