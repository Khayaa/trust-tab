<?php

namespace App\Models;

use App\Enums\CheckoutStatus;
use App\Models\Concerns\BroadcastsTabUpdates;
use Database\Factories\CheckoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tab_id'])]
class Checkout extends Model
{
    use BroadcastsTabUpdates;

    /** @use HasFactory<CheckoutFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => CheckoutStatus::Open->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CheckoutStatus::class,
            'momo_amount' => 'decimal:2',
            'tab_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tab(): BelongsTo
    {
        return $this->belongsTo(Tab::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CheckoutLine::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TabEntry::class);
    }

    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->where('status', CheckoutStatus::Open);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->tab->isMerchant($user);
    }

    public function isOpen(): bool
    {
        return $this->status === CheckoutStatus::Open;
    }

    public function isAwaitingMomo(): bool
    {
        return $this->status === CheckoutStatus::AwaitingMomo;
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this->status === CheckoutStatus::AwaitingConfirmation;
    }

    public function isCompleted(): bool
    {
        return $this->status === CheckoutStatus::Completed;
    }

    public function isHybrid(): bool
    {
        return bccomp($this->momo_amount ?? '0.00', '0.00', 2) === 1
            && bccomp($this->tab_amount ?? '0.00', '0.00', 2) === 1;
    }

    /**
     * Ledger label for a hybrid remainder. One entry, not a split of each line.
     */
    public function remainderDescription(): string
    {
        $this->loadMissing('lines');

        $names = $this->lines
            ->map(fn (CheckoutLine $line) => $line->ledgerDescription())
            ->filter()
            ->implode(', ');

        return mb_substr($names !== '' ? $names : 'Till remainder', 0, 120);
    }

    /**
     * Basket total from snapshotted line prices. Never SQL SUM.
     */
    public function total(): string
    {
        $lines = $this->relationLoaded('lines')
            ? $this->lines
            : $this->lines()->get();

        return $lines->reduce(
            fn (string $total, CheckoutLine $line) => bcadd($total, $line->lineTotal(), 2),
            '0.00',
        );
    }
}
