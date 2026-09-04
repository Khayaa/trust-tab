<?php

namespace App\Support;

use App\Enums\PaymentRequestStatus;
use App\Models\PaymentRequest;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Support\Collection;

class TrustHistory
{
    public function __construct(
        public int $settlements,
        public string $momoSettled,
        public int $paidOnTime,
        public int $openDisputes,
    ) {}

    /**
     * Factual MoMo settlement history for tabs this user holds as customer.
     * Not a score. Pay Now till debits do not count — only tab settlements.
     *
     * @param  Collection<int, Tab>  $tabs
     */
    public static function fromTabs(User $customer, Collection $tabs): self
    {
        $mine = $tabs
            ->filter(fn (Tab $tab) => $tab->isCustomer($customer))
            ->values();

        $openDisputes = $mine->sum(fn (Tab $tab) => (int) ($tab->disputed_count ?? 0));

        if ($mine->isEmpty()) {
            return new self(
                settlements: 0,
                momoSettled: '0.00',
                paidOnTime: 0,
                openDisputes: 0,
            );
        }

        $tabsById = $mine->keyBy(fn (Tab $tab) => $tab->id);

        $payments = PaymentRequest::query()
            ->whereIn('tab_id', $mine->modelKeys())
            ->where('status', PaymentRequestStatus::Successful)
            ->whereNull('checkout_id')
            ->orderBy('id')
            ->get();

        $momoSettled = $payments->reduce(
            fn (string $total, PaymentRequest $payment) => bcadd($total, $payment->amount, 2),
            '0.00',
        );

        $paidOnTime = $payments
            ->filter(function (PaymentRequest $payment) use ($tabsById): bool {
                $tab = $tabsById->get($payment->tab_id);

                return $tab instanceof Tab && self::paidByAgreedDay($payment, $tab);
            })
            ->count();

        return new self(
            settlements: $payments->count(),
            momoSettled: $momoSettled,
            paidOnTime: $paidOnTime,
            openDisputes: $openDisputes,
        );
    }

    protected static function paidByAgreedDay(PaymentRequest $payment, Tab $tab): bool
    {
        if ($payment->completed_at === null || $tab->settlement_day === null) {
            return false;
        }

        $paid = $payment->completed_at->copy()->startOfDay();
        $due = $paid->copy()->day(min($tab->settlement_day, $paid->daysInMonth));

        return $paid->lte($due);
    }
}
