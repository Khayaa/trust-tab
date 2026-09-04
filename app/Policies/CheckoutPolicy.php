<?php

namespace App\Policies;

use App\Models\Checkout;
use App\Models\User;

class CheckoutPolicy
{
    /**
     * The till belongs to the merchant. The customer confirms later, not here.
     */
    public function view(User $user, Checkout $checkout): bool
    {
        return $checkout->isOwnedBy($user);
    }

    public function update(User $user, Checkout $checkout): bool
    {
        return $checkout->isOwnedBy($user) && $checkout->isOpen();
    }

    public function cancel(User $user, Checkout $checkout): bool
    {
        return $checkout->isOwnedBy($user)
            && ($checkout->isOpen() || $checkout->isAwaitingConfirmation());
    }

    /**
     * Pay Now is merchant-led and does not need an agreed tab.
     */
    public function collect(User $user, Checkout $checkout): bool
    {
        return $checkout->isOwnedBy($user)
            && ($checkout->isOpen() || $checkout->isAwaitingMomo());
    }

    /**
     * Putting the basket on the tab needs an accepted agreement.
     */
    public function putOnTab(User $user, Checkout $checkout): bool
    {
        return $checkout->isOwnedBy($user)
            && ($checkout->isOpen() || $checkout->isAwaitingConfirmation())
            && $checkout->tab->hasAcceptedAgreement();
    }

    /**
     * The customer confirms the whole basket, not each line.
     */
    public function confirm(User $user, Checkout $checkout): bool
    {
        return $checkout->tab->isCustomer($user) && $checkout->isAwaitingConfirmation();
    }

    public function dispute(User $user, Checkout $checkout): bool
    {
        return $checkout->tab->isCustomer($user) && $checkout->isAwaitingConfirmation();
    }
}
