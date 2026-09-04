<?php

namespace App\Actions;

use App\Events\TabUpdated;
use App\Exceptions\TabAgreementRequired;
use App\Models\Tab;
use Illuminate\Support\Facades\DB;

class AcceptTabAgreement
{
    /**
     * Only the customer can turn a proposal into an agreement. The merchant
     * proposing the terms cannot mark them accepted — that is enforced by
     * TabPolicy::acceptAgreement before this runs.
     */
    public function handle(Tab $tab): Tab
    {
        return DB::transaction(function () use ($tab): Tab {
            $locked = Tab::query()->whereKey($tab->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->termsAreProposed()) {
                throw new TabAgreementRequired;
            }

            if ($locked->hasAcceptedAgreement()) {
                return $locked;
            }

            $locked->agreement_accepted_at = now();
            $locked->save();

            TabUpdated::dispatch($locked->id);

            return $locked;
        });
    }
}
