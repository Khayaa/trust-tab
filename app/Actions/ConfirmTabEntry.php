<?php

namespace App\Actions;

use App\Enums\TabEntryStatus;
use App\Exceptions\InvalidTabEntryTransition;
use App\Exceptions\TabAgreementRequired;
use App\Exceptions\TabLimitExceeded;
use App\Models\Tab;
use App\Models\TabEntry;
use Illuminate\Support\Facades\DB;

class ConfirmTabEntry
{
    /**
     * The customer agrees the debt is real, which is the moment it starts
     * counting toward the balance.
     *
     * @throws InvalidTabEntryTransition
     */
    public function handle(TabEntry $entry): TabEntry
    {
        return DB::transaction(function () use ($entry) {
            $locked = TabEntry::whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TabEntryStatus::PendingConfirmation) {
                throw InvalidTabEntryTransition::from($locked, TabEntryStatus::Confirmed);
            }

            $tab = Tab::query()->whereKey($locked->tab_id)->lockForUpdate()->firstOrFail();

            if (! $tab->hasAcceptedAgreement()) {
                throw new TabAgreementRequired;
            }

            if (bccomp(bcadd($tab->outstandingBalance(), $locked->amount, 2), $tab->limit_amount, 2) === 1) {
                throw new TabLimitExceeded;
            }

            $locked->status = TabEntryStatus::Confirmed;
            $locked->confirmed_at = now();
            $locked->save();

            return $locked;
        });
    }
}
