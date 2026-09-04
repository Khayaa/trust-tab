<?php

namespace App\Actions;

use App\Enums\TabEntryStatus;
use App\Exceptions\InvalidTabEntryTransition;
use App\Exceptions\TabAgreementRequired;
use App\Exceptions\TabLimitExceeded;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CorrectDisputedTabEntry
{
    /**
     * The merchant concedes the disputed amount and posts a replacement. The
     * original row stays as written — never UPDATE tab_entries.amount.
     *
     * @param  array{description: string, amount: string}  $attributes
     *
     * @throws InvalidTabEntryTransition
     */
    public function handle(TabEntry $entry, User $merchant, array $attributes): TabEntry
    {
        return DB::transaction(function () use ($entry, $merchant, $attributes): TabEntry {
            $locked = TabEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TabEntryStatus::Disputed) {
                throw InvalidTabEntryTransition::from($locked, TabEntryStatus::Withdrawn);
            }

            $tab = Tab::query()->whereKey($locked->tab_id)->lockForUpdate()->firstOrFail();

            if (! $tab->hasAcceptedAgreement()) {
                throw new TabAgreementRequired;
            }

            if ($tab->wouldExceedLimit($attributes['amount'])) {
                throw new TabLimitExceeded;
            }

            $locked->status = TabEntryStatus::Withdrawn;
            $locked->withdrawn_at = now();
            $locked->save();

            return $tab->entries()->create([
                'created_by' => $merchant->id,
                'description' => $attributes['description'],
                'amount' => $attributes['amount'],
                'corrects_entry_id' => $locked->id,
            ]);
        });
    }
}
