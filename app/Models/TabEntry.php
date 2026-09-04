<?php

namespace App\Models;

use App\Enums\TabEntryStatus;
use App\Models\Concerns\BroadcastsTabUpdates;
use Database\Factories\TabEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['tab_id', 'checkout_id', 'corrects_entry_id', 'created_by', 'description', 'amount', 'note'])]
class TabEntry extends Model
{
    use BroadcastsTabUpdates;

    /** @use HasFactory<TabEntryFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => TabEntryStatus::PendingConfirmation->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => TabEntryStatus::class,
            'confirmed_at' => 'datetime',
            'disputed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function tab(): BelongsTo
    {
        return $this->belongsTo(Tab::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    /**
     * The disputed line this pending row replaces. The original amount stays.
     */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_entry_id');
    }

    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'corrects_entry_id');
    }

    public function paymentRequests(): BelongsToMany
    {
        return $this->belongsToMany(PaymentRequest::class, 'payment_request_entries')
            ->withPivot('amount')
            ->withTimestamps();
    }

    /**
     * Confirmed and not yet settled: the entries that count toward the balance
     * and the only entries a settlement may include.
     */
    #[Scope]
    protected function outstanding(Builder $query): Builder
    {
        return $query
            ->where('status', TabEntryStatus::Confirmed)
            ->whereNull('settled_at');
    }

    #[Scope]
    protected function awaitingConfirmation(Builder $query): Builder
    {
        return $query->where('status', TabEntryStatus::PendingConfirmation);
    }

    #[Scope]
    protected function disputed(Builder $query): Builder
    {
        return $query->where('status', TabEntryStatus::Disputed);
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this->status === TabEntryStatus::PendingConfirmation;
    }

    public function isOutstanding(): bool
    {
        return $this->status->isOutstanding() && $this->settled_at === null;
    }
}
