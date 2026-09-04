<?php

namespace App\Models;

use App\Enums\PaymentRequestStatus;
use App\Exceptions\MomoRequestFailed;
use App\Models\Concerns\BroadcastsTabUpdates;
use Database\Factories\PaymentRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tab_id',
    'checkout_id',
    'reference_id',
    'external_reference',
    'amount',
    'currency',
    'payer_msisdn',
    'requested_at',
])]
class PaymentRequest extends Model
{
    use BroadcastsTabUpdates;

    /** @use HasFactory<PaymentRequestFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => PaymentRequestStatus::Created->value,
        'unallocated_amount' => '0.00',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'unallocated_amount' => 'decimal:2',
            'status' => PaymentRequestStatus::class,
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'raw_response' => 'array',
        ];
    }

    public function tab(): BelongsTo
    {
        return $this->belongsTo(Tab::class);
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function snapshotEntries(): HasMany
    {
        return $this->hasMany(PaymentRequestEntry::class);
    }

    /**
     * The tab entries this payment covers. Settlement may only ever touch
     * these, never whatever happens to be outstanding when the callback lands.
     */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(TabEntry::class, 'payment_request_entries')
            ->withPivot('amount')
            ->withTimestamps();
    }

    #[Scope]
    protected function awaitingFinalStatus(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentRequestStatus::Created,
            PaymentRequestStatus::Pending,
        ]);
    }

    /**
     * Payday / tab settlement. Till Pay Now rows have a checkout_id.
     */
    #[Scope]
    protected function forTabSettlement(Builder $query): Builder
    {
        return $query->whereNull('checkout_id');
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    /**
     * Safe to show on the till. Mapped MoMo codes become a sentence; an
     * unknown code is shown in parentheses so a Cloud demo can be diagnosed
     * without opening logs.
     */
    public function failureMessage(): ?string
    {
        if ($this->status !== PaymentRequestStatus::Failed) {
            return null;
        }

        if ($this->failure_reason === 'NEVER_SENT') {
            return 'The request never reached MoMo. Try again.';
        }

        $mapped = MomoRequestFailed::messageForCode($this->failure_reason);

        if ($mapped !== null) {
            return $mapped;
        }

        if (is_string($this->failure_reason) && $this->failure_reason !== '') {
            return "MoMo declined this payment ({$this->failure_reason}).";
        }

        return 'MoMo declined this payment.';
    }
}
