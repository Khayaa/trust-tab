<?php

namespace App\Actions;

use App\Exceptions\TabAgreementRequired;
use App\Exceptions\TabLimitExceeded;
use App\Models\Tab;
use App\Models\TabEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConfirmPendingTabEntries
{
    public function __construct(public ConfirmTabEntry $confirmTabEntry) {}

    /**
     * Confirm every waiting line on the tab in one go. The customer can still
     * inspect and dispute each line; this is only the batch yes.
     *
     * @return Collection<int, TabEntry>
     */
    public function handle(Tab $tab): Collection
    {
        return DB::transaction(function () use ($tab): Collection {
            $locked = Tab::query()->whereKey($tab->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->hasAcceptedAgreement()) {
                throw new TabAgreementRequired;
            }

            $pending = $locked->entries()
                ->awaitingConfirmation()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $total = $pending->reduce(
                fn (string $carry, TabEntry $entry) => bcadd($carry, $entry->amount, 2),
                '0.00',
            );

            if (bccomp(bcadd($locked->outstandingBalance(), $total, 2), $locked->limit_amount ?? '0.00', 2) === 1) {
                throw new TabLimitExceeded;
            }

            return $pending
                ->map(fn (TabEntry $entry) => $this->confirmTabEntry->handle($entry))
                ->values();
        });
    }
}
