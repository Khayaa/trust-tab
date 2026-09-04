<?php

namespace App\Policies;

use App\Enums\TabEntryStatus;
use App\Models\TabEntry;
use App\Models\User;

class TabEntryPolicy
{
    public function view(User $user, TabEntry $entry): bool
    {
        return $entry->tab->isMerchant($user) || $entry->tab->isCustomer($user);
    }

    /**
     * The customer confirms. A merchant confirming their own entry would defeat
     * the point of the record being mutual.
     */
    public function confirm(User $user, TabEntry $entry): bool
    {
        return $entry->tab->isCustomer($user)
            && $entry->status === TabEntryStatus::PendingConfirmation;
    }

    public function dispute(User $user, TabEntry $entry): bool
    {
        return $entry->tab->isCustomer($user)
            && $entry->status === TabEntryStatus::PendingConfirmation;
    }

    /**
     * Disputes need a way out, otherwise a disputed entry sits on the tab
     * forever. The merchant concedes by withdrawing it.
     */
    public function withdraw(User $user, TabEntry $entry): bool
    {
        return $entry->tab->isMerchant($user)
            && $entry->status === TabEntryStatus::Disputed;
    }

    /**
     * Posting a replacement is also the merchant's: they concede the disputed
     * amount and write a new pending line. The original row is never edited.
     */
    public function correct(User $user, TabEntry $entry): bool
    {
        return $entry->tab->isMerchant($user)
            && $entry->status === TabEntryStatus::Disputed;
    }
}
