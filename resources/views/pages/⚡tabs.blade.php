<?php

use App\Actions\OpenTabWithCustomer;
use App\Enums\CheckoutStatus;
use App\Models\Tab;
use App\Support\CustomerMorning;
use App\Support\MerchantMorning;
use App\Support\TrustHistory;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new
#[Title('Your tabs')]
class extends Component
{
    #[Validate('required|string|max:120')]
    public string $customerName = '';

    #[Validate('required|string|max:20')]
    public string $customerMsisdn = '';

    #[Validate('required|numeric|min:0.01|max:9999999999.99|decimal:0,2')]
    public string $limitAmount = '500.00';

    #[Validate('required|integer|min:1|max:31')]
    public string $settlementDay = '25';

    public bool $addingCustomer = false;

    public function askToAddCustomer(): void
    {
        $this->authorize('create', Tab::class);

        $this->addingCustomer = true;
        $this->resetValidation();
    }

    public function cancelAddCustomer(): void
    {
        $this->addingCustomer = false;
        $this->reset('customerName', 'customerMsisdn');
        $this->limitAmount = '500.00';
        $this->settlementDay = '25';
        $this->resetValidation();
    }

    public function openTab(OpenTabWithCustomer $action): void
    {
        $this->authorize('create', Tab::class);

        $validated = $this->validate([
            'customerName' => 'required|string|max:120',
            'customerMsisdn' => 'required|string|max:20',
            'limitAmount' => 'required|numeric|min:0.01|max:9999999999.99|decimal:0,2',
            'settlementDay' => 'required|integer|min:1|max:31',
        ]);

        $tab = $action->handle(
            auth()->user(),
            $validated['customerName'],
            $validated['customerMsisdn'],
            $validated['limitAmount'],
            (int) $validated['settlementDay'],
        );

        $this->reset('customerName', 'customerMsisdn');

        $this->redirectRoute('tabs.show', $tab, navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $user = auth()->user();

        $tabs = Tab::query()
            ->forParticipant($user)
            ->with(['merchant.merchantProfile', 'customer', 'outstandingEntries', 'paymentRequests'])
            ->withCount([
                'entries as awaiting_count' => fn ($query) => $query->awaitingConfirmation(),
                'entries as disputed_count' => fn ($query) => $query->disputed(),
                'checkouts as awaiting_basket_count' => fn ($query) => $query->where('status', CheckoutStatus::AwaitingConfirmation),
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $isMerchant = $user->isMerchant();

        if ($isMerchant) {
            $user->loadMissing('merchantProfile');
        }

        $trustTabs = CustomerMorning::fromTabs($user, $tabs);

        return [
            'tabs' => $tabs,
            'user' => $user,
            'isMerchant' => $isMerchant,
            'morning' => $isMerchant ? MerchantMorning::fromTabs($user, $tabs) : null,
            'trustTabs' => $trustTabs->tabCount > 0 ? $trustTabs : null,
            'history' => $trustTabs->tabCount > 0 ? TrustHistory::fromTabs($user, $tabs) : null,
        ];
    }
};
?>

<div class="space-y-5">
    <div>
        <h1 class="text-xl font-bold tracking-tight text-ink-950">Your tabs</h1>
        <p class="mt-1 text-sm text-ink-500">
            {{ $isMerchant ? 'People who buy on credit from you.' : 'Shops where you have a running tab.' }}
        </p>
    </div>

    @if ($morning)
        <div class="space-y-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">This morning</p>
                <p class="mt-0.5 text-sm font-semibold text-ink-950">{{ $user->merchantProfile?->business_name }}</p>
            </div>

            <div class="overflow-hidden rounded-2xl bg-momo-900">
                <div class="px-5 py-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-momo-300">Owed to you</p>
                    <x-money :amount="$morning->owed" class="mt-3 block text-4xl font-bold text-white" />
                    <p class="mt-2 text-xs font-medium text-momo-300">
                        Across {{ $morning->tabCount }} {{ Str::plural('tab', $morning->tabCount) }}
                    </p>
                </div>
                <div class="h-1 bg-sunshine-400"></div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div class="rounded-xl border border-ink-200 px-4 py-3">
                    <p class="text-xs font-semibold text-ink-500">Waiting</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-ink-950">{{ $morning->waitingOnCustomer }}</p>
                    <p class="mt-1 text-xs text-ink-500">Need the customer</p>
                </div>
                <a href="{{ route('products') }}" wire:navigate
                   class="rounded-xl border border-ink-200 px-4 py-3 transition hover:border-momo-800 hover:bg-momo-50">
                    <p class="text-xs font-semibold text-ink-500">Low stock</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-ink-950">{{ $morning->lowStockCount }}</p>
                    <p class="mt-1 text-xs text-ink-500">
                        @if ($morning->lowStock->isNotEmpty())
                            {{ $morning->lowStock->pluck('name')->join(', ') }}
                        @else
                            Shelf is fine
                        @endif
                    </p>
                </a>
                <div class="rounded-xl border border-ink-200 px-4 py-3">
                    <p class="text-xs font-semibold text-ink-500">Till today</p>
                    <x-money :amount="$morning->todayTill" class="mt-1 block text-2xl font-bold text-ink-950" />
                    <p class="mt-1 text-xs text-ink-500">Completed checkouts</p>
                </div>
                <div class="rounded-xl border border-ink-200 px-4 py-3">
                    <p class="text-xs font-semibold text-ink-500">Disputed</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums {{ $morning->disputed > 0 ? 'text-red-700' : 'text-ink-950' }}">{{ $morning->disputed }}</p>
                    <p class="mt-1 text-xs text-ink-500">{{ $morning->disputed > 0 ? 'Needs you' : 'None waiting' }}</p>
                </div>
            </div>

            <div class="rounded-xl border border-ink-200 px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">This month</p>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <div>
                        <p class="text-xs font-semibold text-ink-500">On TrustTab</p>
                        <x-money :amount="$morning->monthTabSales" class="mt-1 block text-lg font-bold text-ink-950" />
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-ink-500">MoMo settled</p>
                        <x-money :amount="$morning->monthMomo" class="mt-1 block text-lg font-bold text-ink-950" />
                    </div>
                </div>
                @if ($morning->topOnTab->isNotEmpty())
                    <p class="mt-4 text-xs font-semibold text-ink-500">Most on the tab</p>
                    <ul class="mt-2 space-y-1.5">
                        @foreach ($morning->topOnTab as $item)
                            <li wire:key="top-{{ $loop->index }}" class="flex items-center justify-between gap-3 text-sm">
                                <span class="truncate text-ink-950">{{ $item['name'] }}</span>
                                <span class="tabular-nums font-semibold text-ink-700">{{ $item['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    @if ($trustTabs)
        <div class="space-y-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">My TrustTabs</p>
                <p class="mt-0.5 text-sm font-semibold text-ink-950">What you owe, and when payday is.</p>
            </div>

            @if ($trustTabs->shouldRemind())
                @php $reminderTab = $trustTabs->reminderTab(); @endphp
                <div @class([
                    'rounded-xl border border-sunshine-300 bg-sunshine-50 px-4 py-4',
                    'transition hover:border-sunshine-400' => $reminderTab,
                ])>
                    @if ($reminderTab)
                        <a href="{{ route('tabs.show', $reminderTab) }}" wire:navigate class="block">
                    @endif
                        <p class="text-sm font-bold text-ink-950">{{ $trustTabs->paydayLabel() }}</p>
                        <p class="mt-1 text-sm text-ink-600">
                            @if ($trustTabs->reminderShop())
                                Your <x-money :amount="$trustTabs->reminderAmount()" class="font-semibold" />
                                TrustTab at {{ $trustTabs->reminderShop() }} is due {{ $trustTabs->reminderWhen() }}.
                            @else
                                <x-money :amount="$trustTabs->reminderAmount()" class="font-semibold" />
                                across {{ $trustTabs->dueSoon->count() }} TrustTabs is due {{ $trustTabs->reminderWhen() }}.
                            @endif
                        </p>
                        @if ($reminderTab)
                            <p class="mt-2 text-xs font-bold text-momo-800">Pay with MoMo</p>
                        @endif
                    @if ($reminderTab)
                        </a>
                    @endif
                </div>
            @endif

            <div class="overflow-hidden rounded-2xl bg-momo-900">
                <div class="px-5 py-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-momo-300">You owe</p>
                    <x-money :amount="$trustTabs->owed" class="mt-3 block text-4xl font-bold text-white" />
                    <p class="mt-2 text-xs font-medium text-momo-300">
                        Across {{ $trustTabs->tabCount }} {{ Str::plural('tab', $trustTabs->tabCount) }}
                    </p>
                </div>
                <div class="h-1 bg-sunshine-400"></div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div class="rounded-xl border border-ink-200 px-4 py-3">
                    <p class="text-xs font-semibold text-ink-500">Waiting</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-ink-950">{{ $trustTabs->waitingOnYou }}</p>
                    <p class="mt-1 text-xs text-ink-500">Need you</p>
                </div>
                <div class="rounded-xl border border-ink-200 px-4 py-3">
                    <p class="text-xs font-semibold text-ink-500">Payday</p>
                    <p class="mt-1 text-lg font-bold text-ink-950">{{ $trustTabs->paydayLabel() }}</p>
                    <p class="mt-1 text-xs text-ink-500">
                        @if ($trustTabs->shouldRemind())
                            Pay with MoMo
                        @elseif ($trustTabs->nextDue)
                            Agreed payday
                        @else
                            Nothing to settle
                        @endif
                    </p>
                </div>
            </div>

            @if ($history)
                <div class="rounded-xl border border-ink-200 px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">Trust History</p>
                    <p class="mt-0.5 text-xs text-ink-500">What you have settled through MoMo. This is not a score.</p>
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div>
                            <p class="text-2xl font-bold tabular-nums text-ink-950">{{ $history->settlements }}</p>
                            <p class="mt-1 text-xs text-ink-500">Settled with MoMo</p>
                        </div>
                        <div>
                            <x-money :amount="$history->momoSettled" class="block text-2xl font-bold text-ink-950" />
                            <p class="mt-1 text-xs text-ink-500">Through MoMo</p>
                        </div>
                        <div>
                            <p class="text-2xl font-bold tabular-nums text-ink-950">{{ $history->paidOnTime }}</p>
                            <p class="mt-1 text-xs text-ink-500">Paid by the agreed day</p>
                        </div>
                        <div>
                            <p class="text-2xl font-bold tabular-nums {{ $history->openDisputes > 0 ? 'text-red-700' : 'text-ink-950' }}">{{ $history->openDisputes }}</p>
                            <p class="mt-1 text-xs text-ink-500">Open disputes</p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if ($isMerchant)
        <button type="button" wire:click="askToAddCustomer"
                class="w-full rounded-xl bg-sunshine-400 px-4 py-3.5 text-sm font-bold text-black transition hover:bg-sunshine-500">
            Add a customer
        </button>

        <div wire:loading.delay wire:target="openTab" class="space-y-2">
            @foreach (range(1, 2) as $row)
                <div wire:key="opening-skeleton-{{ $row }}" class="flex items-center gap-3 rounded-xl border border-ink-200 px-4 py-3" aria-hidden="true">
                    <x-skeleton class="size-10 shrink-0 rounded-full" />
                    <div class="min-w-0 flex-1 space-y-2">
                        <x-skeleton class="h-4 w-40" />
                        <x-skeleton class="h-3 w-28" />
                    </div>
                    <x-skeleton class="h-4 w-16" />
                </div>
            @endforeach
        </div>
    @endif

    <ul class="space-y-2">
        @foreach ($tabs as $tab)
            @php
                $isMerchant = $tab->isMerchant($user);
                $other = $isMerchant ? $tab->customer : $tab->merchant;
                $label = $isMerchant
                    ? $other->name
                    : ($tab->merchant->merchantProfile?->business_name ?? $other->name);
            @endphp

            <li wire:key="tab-{{ $tab->id }}">
                <a href="{{ route('tabs.show', $tab) }}" wire:navigate
                   class="flex items-center gap-3 rounded-xl border border-ink-200 bg-white px-4 py-3 transition hover:border-momo-800 hover:bg-momo-50">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-momo-100 text-sm font-bold text-momo-800">
                        {{ Str::of($label)->explode(' ')->map(fn ($part) => Str::substr($part, 0, 1))->take(2)->implode('') }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-ink-950">{{ $label }}</span>
                        <span class="mt-0.5 block text-xs text-ink-500">
                            @if ($tab->disputed_count > 0)
                                {{-- A dispute stalls until the merchant acts, so it outranks the rest. --}}
                                <span class="font-semibold text-red-700">
                                    {{ $tab->disputed_count }} disputed{{ $isMerchant ? ', needs you' : '' }}
                                </span>
                            @elseif ($tab->awaiting_count + $tab->awaiting_basket_count > 0)
                                <span class="font-semibold text-sunshine-700">
                                    @if ($tab->awaiting_basket_count > 0)
                                        Basket awaiting {{ $isMerchant ? 'their' : 'your' }} confirmation
                                    @else
                                        {{ $tab->awaiting_count }} awaiting {{ $isMerchant ? 'their' : 'your' }} confirmation
                                    @endif
                                </span>
                            @else
                                All items confirmed
                            @endif
                        </span>
                    </span>

                    <span class="text-right">
                        <x-money :amount="$tab->outstandingBalance()" class="block text-sm font-bold text-momo-800" />
                        @if ($tab->hasAcceptedAgreement())
                            <span class="block text-xs {{ ! $isMerchant && $tab->isDueSoon() ? 'font-semibold text-sunshine-700' : 'text-ink-400' }}">
                                of <x-money :amount="$tab->limit_amount" />
                                · {{ ! $isMerchant && $tab->isDueSoon() ? $tab->paydayUrgencyLabel() : 'due '.($tab->nextDueDate()?->format('j M')) }}
                            </span>
                        @elseif ($tab->termsAreProposed())
                            <span class="block text-xs font-semibold text-sunshine-700">Awaiting agreement</span>
                        @else
                            <span class="block text-xs text-ink-400">outstanding</span>
                        @endif
                    </span>
                </a>
            </li>
        @endforeach
    </ul>

    @if ($tabs->isEmpty())
        <p class="rounded-xl bg-ink-50 px-4 py-10 text-center text-sm text-ink-500">
            No tabs yet.
        </p>
    @endif

    @if ($addingCustomer)
        @teleport('body')
            <div wire:click="cancelAddCustomer"
                 x-data
                 x-on:keydown.escape.window="$wire.cancelAddCustomer()"
                 class="fixed inset-0 z-50 flex items-center justify-center bg-momo-950/60 p-4"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="add-customer-title">
                <form wire:click.stop wire:submit="openTab" class="max-h-[90vh] w-full max-w-sm overflow-y-auto rounded-2xl bg-white p-5 shadow-lg">
                    <p id="add-customer-title" class="text-base font-bold text-ink-950">Add a customer</p>
                    <p class="mt-1 text-sm text-ink-600">Use the MoMo number they already pay with. That is how they will see this tab.</p>

                    <div class="mt-4 space-y-3">
                        <div>
                            <label for="customerName" class="block text-xs font-semibold text-ink-600">Their name</label>
                            <input wire:model="customerName" id="customerName" type="text" autocomplete="name" required autofocus
                                   class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                            @error('customerName')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="customerMsisdn" class="block text-xs font-semibold text-ink-600">MoMo number</label>
                            <input wire:model="customerMsisdn" id="customerMsisdn" type="tel" inputmode="tel" autocomplete="tel" required
                                   placeholder="082 123 4567"
                                   class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                            @error('customerMsisdn')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="limitAmount" class="block text-xs font-semibold text-ink-600">Tab limit</label>
                                <input wire:model="limitAmount" id="limitAmount" type="text" inputmode="decimal" required
                                       class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm tabular-nums focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                                @error('limitAmount')
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="settlementDay" class="block text-xs font-semibold text-ink-600">Payday (1–31)</label>
                                <input wire:model="settlementDay" id="settlementDay" type="number" min="1" max="31" required
                                       class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm tabular-nums focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                                @error('settlementDay')
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        <p class="text-xs text-ink-500">They have to agree this limit and payday before anything goes on the tab.</p>
                    </div>

                    <div class="mt-5 flex gap-2">
                        <button type="button" wire:click="cancelAddCustomer" wire:loading.attr="disabled" wire:target="openTab"
                                class="flex-1 rounded-lg border border-ink-300 px-3 py-2.5 text-sm font-semibold text-ink-700 transition hover:bg-ink-50">
                            Not now
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="openTab"
                                class="flex-1 rounded-lg bg-sunshine-400 px-3 py-2.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                            <span class="in-data-loading:hidden">Open their tab</span>
                            <span class="not-in-data-loading:hidden">Opening…</span>
                        </button>
                    </div>
                </form>
            </div>
        @endteleport
    @endif
</div>
