<?php

namespace App\Actions;

use App\Enums\TabEntryStatus;
use App\Exceptions\InvalidTabEntryTransition;
use App\Models\TabEntry;
use Illuminate\Support\Facades\DB;

class DisputeTabEntry
{
    /**
     * The customer says this is not theirs. A disputed entry never counts toward
     * the balance, so a disagreement can never become a demand for payment.
     *
     * @throws InvalidTabEntryTransition
     */
    public function handle(TabEntry $entry, ?string $reason = null): TabEntry
    {
        return DB::transaction(function () use ($entry, $reason) {
            $locked = TabEntry::whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TabEntryStatus::PendingConfirmation) {
                throw InvalidTabEntryTransition::from($locked, TabEntryStatus::Disputed);
            }

            $locked->status = TabEntryStatus::Disputed;
            $locked->disputed_at = now();
            $locked->note = $reason ?? $locked->note;
            $locked->save();

            return $locked;
        });
    }
}
