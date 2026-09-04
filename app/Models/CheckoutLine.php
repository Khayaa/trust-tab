<?php

namespace App\Models;

use Database\Factories\CheckoutLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'checkout_id',
    'product_id',
    'name',
    'unit_price',
    'quantity',
])]
class CheckoutLine extends Model
{
    /** @use HasFactory<CheckoutLineFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
        ];
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lineTotal(): string
    {
        return bcmul($this->unit_price, (string) $this->quantity, 2);
    }

    /**
     * Ledger label after the customer confirms the basket.
     */
    public function ledgerDescription(): string
    {
        $label = $this->quantity === 1
            ? $this->name
            : $this->quantity.'× '.$this->name;

        return mb_substr($label, 0, 120);
    }
}
