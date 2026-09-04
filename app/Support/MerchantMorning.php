<?php

namespace App\Support;

use App\Enums\CheckoutStatus;
use App\Enums\PaymentRequestStatus;
use App\Enums\TabEntryStatus;
use App\Models\Checkout;
use App\Models\PaymentRequest;
use App\Models\Product;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Support\Collection;

class MerchantMorning
{
    /**
     * @param  Collection<int, Product>  $lowStock
     * @param  Collection<int, array{name: string, count: int}>  $topOnTab
     */
    public function __construct(
        public string $owed,
        public int $tabCount,
        public int $waitingOnCustomer,
        public int $disputed,
        public int $lowStockCount,
        public Collection $lowStock,
        public string $todayTill,
        public string $monthTabSales,
        public string $monthMomo,
        public Collection $topOnTab,
    ) {}

    /**
     * Snapshot for the shop's home screen. Money is bcadd of already-loaded
     * outstanding balances, never SQL SUM. Only tabs this user owns as
     * merchant count — a shopkeeper who also buys elsewhere is not mixed in.
     *
     * @param  Collection<int, Tab>  $tabs
     */
    public static function fromTabs(User $merchant, Collection $tabs): self
    {
        $owned = $tabs
            ->filter(fn (Tab $tab) => $tab->isMerchant($merchant))
            ->values();

        $owed = $owned->reduce(
            fn (string $total, Tab $tab) => bcadd($total, $tab->outstandingBalance(), 2),
            '0.00',
        );

        $waitingOnCustomer = $owned
            ->filter(fn (Tab $tab) => ($tab->awaiting_count ?? 0) + ($tab->awaiting_basket_count ?? 0) > 0)
            ->count();

        $disputed = $owned
            ->filter(fn (Tab $tab) => ($tab->disputed_count ?? 0) > 0)
            ->count();

        $lowStock = Product::query()
            ->whereBelongsTo($merchant, 'merchant')
            ->active()
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $todayTill = '0.00';
        $monthTabSales = '0.00';
        $monthMomo = '0.00';
        $topOnTab = collect();

        if ($owned->isNotEmpty()) {
            $tabIds = $owned->modelKeys();
            $month = [now()->startOfMonth(), now()->endOfMonth()];

            $todayTill = Checkout::query()
                ->whereIn('tab_id', $tabIds)
                ->where('status', CheckoutStatus::Completed)
                ->whereToday('completed_at')
                ->get()
                ->reduce(
                    fn (string $total, Checkout $checkout) => bcadd(
                        $total,
                        bcadd($checkout->momo_amount ?? '0.00', $checkout->tab_amount ?? '0.00', 2),
                        2,
                    ),
                    '0.00',
                );

            $monthEntries = TabEntry::query()
                ->whereIn('tab_id', $tabIds)
                ->whereIn('status', [TabEntryStatus::Confirmed, TabEntryStatus::Settled])
                ->whereBetween('confirmed_at', $month)
                ->orderBy('id')
                ->get();

            $monthTabSales = $monthEntries->reduce(
                fn (string $total, TabEntry $entry) => bcadd($total, $entry->amount, 2),
                '0.00',
            );

            $topOnTab = $monthEntries
                ->groupBy('description')
                ->map(fn (Collection $group, string $name): array => [
                    'name' => $name,
                    'count' => $group->count(),
                ])
                ->sort(fn (array $left, array $right): int => $right['count'] <=> $left['count'] ?: $left['name'] <=> $right['name'])
                ->values()
                ->take(3);

            $monthMomo = PaymentRequest::query()
                ->whereIn('tab_id', $tabIds)
                ->where('status', PaymentRequestStatus::Successful)
                ->whereBetween('completed_at', $month)
                ->get()
                ->reduce(
                    fn (string $total, PaymentRequest $payment) => bcadd($total, $payment->amount, 2),
                    '0.00',
                );
        }

        return new self(
            owed: $owed,
            tabCount: $owned->count(),
            waitingOnCustomer: $waitingOnCustomer,
            disputed: $disputed,
            lowStockCount: $lowStock->count(),
            lowStock: $lowStock->take(3),
            todayTill: $todayTill,
            monthTabSales: $monthTabSales,
            monthMomo: $monthMomo,
            topOnTab: $topOnTab,
        );
    }
}
