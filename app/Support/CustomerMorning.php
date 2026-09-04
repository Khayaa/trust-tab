<?php

namespace App\Support;

use App\Models\Tab;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CustomerMorning
{
    /**
     * @param  Collection<int, Tab>  $dueSoon
     */
    public function __construct(
        public string $owed,
        public int $tabCount,
        public int $waitingOnYou,
        public ?Carbon $nextDue,
        public Collection $dueSoon,
    ) {}

    /**
     * Snapshot for the customer's home screen. Money is bcadd of already-loaded
     * outstanding balances, never SQL SUM. Only tabs this user holds as
     * customer count — a shopkeeper who also buys elsewhere is not mixed in.
     *
     * @param  Collection<int, Tab>  $tabs
     */
    public static function fromTabs(User $customer, Collection $tabs): self
    {
        $mine = $tabs
            ->filter(fn (Tab $tab) => $tab->isCustomer($customer))
            ->values();

        $owed = $mine->reduce(
            fn (string $total, Tab $tab) => bcadd($total, $tab->outstandingBalance(), 2),
            '0.00',
        );

        $waitingOnYou = $mine
            ->filter(fn (Tab $tab) => ($tab->awaiting_count ?? 0) + ($tab->awaiting_basket_count ?? 0) > 0)
            ->count();

        $owedTabs = $mine->filter(
            fn (Tab $tab) => $tab->hasAcceptedAgreement()
                && bccomp($tab->outstandingBalance(), '0.00', 2) === 1,
        );

        $nextDue = $owedTabs
            ->map(fn (Tab $tab) => $tab->nextDueDate())
            ->filter()
            ->sortBy(fn (Carbon $date) => $date->timestamp)
            ->first();

        $dueSoon = $owedTabs
            ->filter(fn (Tab $tab) => $tab->isDueSoon())
            ->values();

        return new self(
            owed: $owed,
            tabCount: $mine->count(),
            waitingOnYou: $waitingOnYou,
            nextDue: $nextDue,
            dueSoon: $dueSoon,
        );
    }

    public function paydayLabel(): string
    {
        if ($this->nextDue === null) {
            return 'Nothing due';
        }

        if ($this->nextDue->isToday()) {
            return 'Due today';
        }

        if ($this->nextDue->isTomorrow()) {
            return 'Due tomorrow';
        }

        return 'Due '.$this->nextDue->format('j M');
    }

    public function shouldRemind(): bool
    {
        return $this->dueSoon->isNotEmpty();
    }

    public function reminderWhen(): ?string
    {
        if ($this->dueSoon->isEmpty()) {
            return null;
        }

        if ($this->dueSoon->contains(fn (Tab $tab) => $tab->nextDueDate()?->isToday())) {
            return 'today';
        }

        return 'tomorrow';
    }

    public function reminderAmount(): string
    {
        return $this->dueSoon->reduce(
            fn (string $total, Tab $tab) => bcadd($total, $tab->outstandingBalance(), 2),
            '0.00',
        );
    }

    public function reminderShop(): ?string
    {
        if ($this->dueSoon->count() !== 1) {
            return null;
        }

        $tab = $this->dueSoon->first();

        return $tab->merchant->merchantProfile?->business_name ?? $tab->merchant->name;
    }

    public function reminderTab(): ?Tab
    {
        return $this->dueSoon->count() === 1 ? $this->dueSoon->first() : null;
    }
}
