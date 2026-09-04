<?php

namespace App\Models;

use App\Enums\CheckoutStatus;
use App\Enums\PaymentRequestStatus;
use App\Enums\TabEntryStatus;
use App\Enums\TabStatus;
use Database\Factories\TabFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['merchant_id', 'customer_id', 'status', 'expected_payment_date'])]
class Tab extends Model
{
    /** @use HasFactory<TabFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => TabStatus::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TabStatus::class,
            'limit_amount' => 'decimal:2',
            'settlement_day' => 'integer',
            'agreement_accepted_at' => 'datetime',
            'expected_payment_date' => 'date',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TabEntry::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function checkouts(): HasMany
    {
        return $this->hasMany(Checkout::class);
    }

    public function openCheckout(): ?Checkout
    {
        return $this->checkouts()->open()->latest('id')->first();
    }

    public function currentTillCheckout(): ?Checkout
    {
        return $this->checkouts()
            ->whereIn('status', [
                CheckoutStatus::Open,
                CheckoutStatus::AwaitingMomo,
                CheckoutStatus::AwaitingConfirmation,
            ])
            ->latest('id')
            ->first();
    }

    /**
     * Entries that make up the amount the customer currently owes.
     */
    public function outstandingEntries(): HasMany
    {
        return $this->entries()->outstanding();
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', TabStatus::Active);
    }

    /**
     * Tabs where the given user is either side of the relationship.
     */
    #[Scope]
    protected function forParticipant(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('merchant_id', $user->id)
            ->orWhere('customer_id', $user->id));
    }

    /**
     * The authoritative balance, always derived from the ledger.
     *
     * Confirmed unsettled lines minus unallocated remainder of successful
     * payments. Never SQL SUM: SQLite returns a float, MySQL a DECIMAL.
     * Do not subtract a payment's full amount once its covered rows are
     * already dropped from the outstanding set — that double-counts.
     */
    public function outstandingBalance(): string
    {
        $amounts = $this->relationLoaded('outstandingEntries')
            ? $this->outstandingEntries->pluck('amount')
            : $this->entries()->outstanding()->pluck('amount');

        $total = $amounts->reduce(fn (string $total, string $amount) => bcadd($total, $amount, 2), '0.00');

        return bcsub($total, $this->unallocatedCredits(), 2);
    }

    /**
     * Remainder of successful payments that has not yet covered a full line.
     */
    public function unallocatedCredits(): string
    {
        $amounts = $this->relationLoaded('paymentRequests')
            ? $this->paymentRequests
                ->filter(fn (PaymentRequest $payment) => $payment->status === PaymentRequestStatus::Successful)
                ->pluck('unallocated_amount')
            : $this->paymentRequests()
                ->where('status', PaymentRequestStatus::Successful)
                ->pluck('unallocated_amount');

        return $amounts->reduce(fn (string $total, string $amount) => bcadd($total, $amount, 2), '0.00');
    }

    public function isMerchant(User $user): bool
    {
        return $this->merchant_id === $user->id;
    }

    public function isCustomer(User $user): bool
    {
        return $this->customer_id === $user->id;
    }

    public function hasEntryAwaiting(): bool
    {
        if ($this->entries()->where('status', TabEntryStatus::PendingConfirmation)->exists()) {
            return true;
        }

        return $this->checkouts()
            ->where('status', CheckoutStatus::AwaitingConfirmation)
            ->exists();
    }

    public function termsAreProposed(): bool
    {
        return $this->limit_amount !== null && $this->settlement_day !== null;
    }

    public function hasAcceptedAgreement(): bool
    {
        return $this->termsAreProposed() && $this->agreement_accepted_at !== null;
    }

    /**
     * Confirmed outstanding plus lines still waiting on the customer. Used to
     * decide whether a new add would break the agreed limit.
     */
    public function committedAmount(): string
    {
        $pending = $this->entries()
            ->awaitingConfirmation()
            ->pluck('amount')
            ->reduce(fn (string $total, string $amount) => bcadd($total, $amount, 2), '0.00');

        $awaitingBaskets = $this->checkouts()
            ->where(function (Builder $query): void {
                $query->where('status', CheckoutStatus::AwaitingConfirmation)
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', CheckoutStatus::AwaitingMomo)
                            ->where('tab_amount', '>', 0);
                    });
            })
            ->pluck('tab_amount')
            ->reduce(fn (string $total, ?string $amount) => bcadd($total, $amount ?? '0.00', 2), '0.00');

        return bcadd(bcadd($this->outstandingBalance(), $pending, 2), $awaitingBaskets, 2);
    }

    /**
     * Remaining room under the agreed limit. Null when no limit has been set.
     */
    public function availableAmount(): ?string
    {
        if ($this->limit_amount === null) {
            return null;
        }

        return bcsub($this->limit_amount, $this->outstandingBalance(), 2);
    }

    public function wouldExceedLimit(string $amount): bool
    {
        if ($this->limit_amount === null) {
            return true;
        }

        return bccomp(bcadd($this->committedAmount(), $amount, 2), $this->limit_amount, 2) === 1;
    }

    public function nextDueDate(?Carbon $from = null): ?Carbon
    {
        if ($this->settlement_day === null) {
            return null;
        }

        $from = ($from ?? now())->copy()->startOfDay();
        $candidate = $from->copy()->day(min($this->settlement_day, $from->daysInMonth));

        if ($candidate->lt($from)) {
            $nextMonth = $from->copy()->addMonthNoOverflow()->startOfMonth();

            return $nextMonth->day(min($this->settlement_day, $nextMonth->daysInMonth));
        }

        return $candidate;
    }

    public function settlementDayLabel(): ?string
    {
        if ($this->settlement_day === null) {
            return null;
        }

        $suffix = match ($this->settlement_day % 10) {
            1 => $this->settlement_day % 100 === 11 ? 'th' : 'st',
            2 => $this->settlement_day % 100 === 12 ? 'th' : 'nd',
            3 => $this->settlement_day % 100 === 13 ? 'th' : 'rd',
            default => 'th',
        };

        return $this->settlement_day.$suffix;
    }

    /**
     * Payday is today or tomorrow and there is still confirmed outstanding.
     */
    public function isDueSoon(?Carbon $from = null): bool
    {
        $from = ($from ?? now())->copy()->startOfDay();
        $due = $this->nextDueDate($from);

        if ($due === null || ! $this->hasAcceptedAgreement()) {
            return false;
        }

        if (bccomp($this->outstandingBalance(), '0.00', 2) !== 1) {
            return false;
        }

        return $due->equalTo($from) || $due->equalTo($from->copy()->addDay());
    }

    public function paydayUrgencyLabel(?Carbon $from = null): ?string
    {
        $from = ($from ?? now())->copy()->startOfDay();
        $due = $this->nextDueDate($from);

        if ($due === null) {
            return null;
        }

        if ($due->equalTo($from)) {
            return 'Due today';
        }

        if ($due->equalTo($from->copy()->addDay())) {
            return 'Due tomorrow';
        }

        return 'Due '.$due->format('j M');
    }
}
