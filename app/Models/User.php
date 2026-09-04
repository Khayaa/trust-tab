<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'msisdn', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function merchantProfile(): HasOne
    {
        return $this->hasOne(MerchantProfile::class);
    }

    /**
     * Tabs this user owns as the merchant.
     */
    public function merchantTabs(): HasMany
    {
        return $this->hasMany(Tab::class, 'merchant_id');
    }

    /**
     * Tabs this user appears on as the customer.
     */
    public function customerTabs(): HasMany
    {
        return $this->hasMany(Tab::class, 'customer_id');
    }

    /**
     * Catalogue items this merchant sells. Stock is taken later at checkout.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'merchant_id');
    }

    /**
     * A user may act as a merchant once they have a merchant profile. Roles are
     * not fixed on the user, because one person can be both.
     */
    public function isMerchant(): bool
    {
        return $this->merchantProfile()->exists();
    }
}
