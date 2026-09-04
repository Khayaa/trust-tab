<?php

namespace App\Models;

use Database\Factories\PaymentRequestEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payment_request_id', 'tab_entry_id', 'amount'])]
class PaymentRequestEntry extends Model
{
    /** @use HasFactory<PaymentRequestEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function tabEntry(): BelongsTo
    {
        return $this->belongsTo(TabEntry::class);
    }
}
