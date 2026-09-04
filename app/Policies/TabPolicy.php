<?php

namespace App\Policies;

use App\Enums\TabStatus;
use App\Models\Tab;
use App\Models\User;

class TabPolicy
{
    /**
     * Opening a tab is extending credit. Only a merchant may do that, and they
     * do it by the customer's MoMo number — standing at the counter is the
     * acceptance, so there is no invite for the customer to approve.
     */
    public function create(User $user): bool
    {
        return $user->isMerchant();
    }

    /**
     * Both sides of a tab can see it. Nobody else can, which is the whole
     * privacy promise of the product.
     */
    public function view(User $user, Tab $tab): bool
    {
        return $tab->isMerchant($user) || $tab->isCustomer($user);
    }

    /**
     * The till is merchant-only. Pay Now later will not need an accepted
     * agreement; TrustTab checkout will. Do not require one here.
     */
    public function runTill(User $user, Tab $tab): bool
    {
        return $tab->isMerchant($user) && $tab->status === TabStatus::Active;
    }

    /**
     * Only the merchant records what was taken on credit, and only while the
     * tab is open and both sides have accepted the limit and payday.
     */
    public function addEntry(User $user, Tab $tab): bool
    {
        return $tab->isMerchant($user)
            && $tab->status === TabStatus::Active
            && $tab->hasAcceptedAgreement();
    }

    /**
     * The merchant proposes a limit and payday. Changing them requires the
     * customer to accept again.
     */
    public function proposeTerms(User $user, Tab $tab): bool
    {
        return $tab->isMerchant($user) && $tab->status === TabStatus::Active;
    }

    /**
     * Only the customer can accept. The merchant cannot mark their own
     * proposal as agreed.
     */
    public function acceptAgreement(User $user, Tab $tab): bool
    {
        return $tab->isCustomer($user)
            && $tab->status === TabStatus::Active
            && $tab->termsAreProposed()
            && ! $tab->hasAcceptedAgreement();
    }

    /**
     * Only the customer pays, and only when something is actually owed.
     */
    public function settle(User $user, Tab $tab): bool
    {
        return $tab->isCustomer($user)
            && $tab->status === TabStatus::Active
            && bccomp($tab->outstandingBalance(), '0.00', 2) === 1;
    }

    /**
     * Confirm every waiting line at once. Same person as a single confirm.
     */
    public function confirmPending(User $user, Tab $tab): bool
    {
        return $tab->isCustomer($user) && $tab->status === TabStatus::Active;
    }

    public function close(User $user, Tab $tab): bool
    {
        return $tab->isMerchant($user) && $tab->status === TabStatus::Active;
    }
}
