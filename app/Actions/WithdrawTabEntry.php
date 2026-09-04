<?php

namespace App\Actions;

use App\Enums\TabEntryStatus;
use App\Exceptions\InvalidTabEntryTransition;
use App\Models\TabEntry;
use Illuminate\Support\Facades\DB;

class WithdrawTabEntry
{
    /**
     * The merchant concedes a disputed entry. This is the only exit from a
     * dispute, and it is deliberately the merchant's to take: the customer
     * cannot erase a debt by objecting to it.
     *
     * @throws InvalidTabEntryTransition
     */
    public function handle(TabEntry $entry): TabEntry
    {
        return DB::transaction(function () use ($entry) {
            $locked = TabEntry::whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TabEntryStatus::Disputed) {
                throw InvalidTabEntryTransition::from($locked, TabEntryStatus::Withdrawn);
            }

            $locked->status = TabEntryStatus::Withdrawn;
            $locked->withdrawn_at = now();
            $locked->save();

            return $locked;
        });
    }
}
