<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * The catalogue is the merchant's till book. Customers never see it.
     */
    public function viewAny(User $user): bool
    {
        return $user->isMerchant();
    }

    public function view(User $user, Product $product): bool
    {
        return $product->isOwnedBy($user);
    }

    public function create(User $user): bool
    {
        return $user->isMerchant();
    }

    public function update(User $user, Product $product): bool
    {
        return $product->isOwnedBy($user);
    }
}
