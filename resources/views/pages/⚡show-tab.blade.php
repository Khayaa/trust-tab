<?php

use App\Actions\AcceptTabAgreement;
use App\Actions\AddTabEntry;
use App\Actions\ApplyMomoResult;
use App\Actions\ConfirmCheckout;
use App\Actions\ConfirmPendingTabEntries;
use App\Actions\ConfirmTabEntry;
use App\Actions\CorrectDisputedTabEntry;
use App\Actions\DisputeCheckout;
use App\Actions\DisputeTabEntry;
use App\Actions\ProposeTabTerms;
use App\Actions\RequestTabSettlement;
use App\Actions\WithdrawTabEntry;
use App\Enums\CheckoutStatus;
use App\Enums\PaymentRequestStatus;
use App\Enums\TabEntryStatus;
use App\Exceptions\InvalidTabEntryTransition;
use App\Exceptions\MomoRequestFailed;
use App\Exceptions\TabAgreementRequired;
use App\Exceptions\TabLimitExceeded;
use App\Models\Checkout;
use App\Models\PaymentRequest;
use App\Models\Product;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Support\Msisdn;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new
#[Title('Tab')]
class extends Component
{
    public Tab $tab;

    #[Validate('required|string|max:120')]
    public string $description = '';

    #[Validate('required|numeric|min:0.01|max:9999999999.99|decimal:0,2')]
    public string $amount = '';

    public string $query = '';

    public bool $addingEntry = false;

    #[Validate('required|numeric|min:0.01|max:9999999999.99|decimal:0,2')]
    public string $limitAmount = '500.00';

    #[Validate('required|integer|min:1|max:31')]
    public string $settlementDay = '25';

    public bool $confirmingSettlement = false;

    public string $settlementAmount = '';

    public ?int $disputingEntryId = null;

    public string $disputeReason = '';

    public ?int $correctingEntryId = null;

    public string $correctionAmount = '';

    public string $correctionDescription = '';

    public function mount(Tab $tab): void
    {
        $this->authorize('view', $tab);

        $this->tab = $tab;
        $this->fillTermsFromTab();
    }

    public function askToAddEntry(): void
    {
        $this->authorize('addEntry', $this->tab);

        $this->addingEntry = true;
        $this->reset('description', 'amount');
        $this->resetValidation();
    }

    public function cancelAddEntry(): void
    {
        $this->addingEntry = false;
        $this->reset('description', 'amount', 'query');
        $this->resetValidation();
    }

    public function selectProduct(int $productId): void
    {
        $this->authorize('addEntry', $this->tab);

        $product = Product::query()
            ->where('merchant_id', $this->tab->merchant_id)
            ->active()
            ->whereKey($productId)
            ->firstOrFail();

        $this->description = $product->name;
        $this->amount = $product->selling_price;
        $this->query = $product->name;
        $this->resetValidation();
    }

    public function addEntry(AddTabEntry $action): void
    {
        $this->authorize('addEntry', $this->tab);

        $validated = $this->validate([
            'description' => 'required|string|max:120',
            'amount' => 'required|numeric|min:0.01|max:9999999999.99|decimal:0,2',
        ]);

        try {
            $action->handle($this->tab, auth()->user(), $validated);
        } catch (TabAgreementRequired|TabLimitExceeded $exception) {
            $this->addingEntry = true;
            $this->addError('amount', $exception->userMessage());

            return;
        }

        $this->addingEntry = false;
        $this->reset('description', 'amount', 'query');
    }

    public function proposeTerms(ProposeTabTerms $action): void
    {
        $this->authorize('proposeTerms', $this->tab);

        $validated = $this->validate([
            'limitAmount' => 'required|numeric|min:0.01|max:9999999999.99|decimal:0,2',
            'settlementDay' => 'required|integer|min:1|max:31',
        ]);

        $action->handle(
            $this->tab,
            auth()->user(),
            $validated['limitAmount'],
            (int) $validated['settlementDay'],
        );
        $this->tab->refresh();
        $this->fillTermsFromTab();
    }

    public function acceptAgreement(AcceptTabAgreement $action): void
    {
        $this->authorize('acceptAgreement', $this->tab);

        try {
            $action->handle($this->tab);
            $this->tab->refresh();
        } catch (TabAgreementRequired $exception) {
            $this->addError('agreement', $exception->userMessage());
        }
    }

    public function confirmCheckout(ConfirmCheckout $action): void
    {
        $checkout = $this->awaitingCheckout();

        if ($checkout === null) {
            return;
        }

        $this->authorize('confirm', $checkout);

        try {
            $action->handle($checkout);
        } catch (TabAgreementRequired|TabLimitExceeded|MomoRequestFailed $exception) {
            $this->addError('entry', $exception->userMessage());
        }
    }

    public function disputeCheckout(DisputeCheckout $action): void
    {
        $checkout = $this->awaitingCheckout();

        if ($checkout === null) {
            return;
        }

        $this->authorize('dispute', $checkout);
        $action->handle($checkout);
    }

    public function confirm(int $entryId, ConfirmTabEntry $action): void
    {
        $this->runTransition($entryId, 'confirm', fn (TabEntry $entry) => $action->handle($entry));
    }

    public function askToDispute(int $entryId): void
    {
        $entry = $this->tab->entries()->findOrFail($entryId);

        $this->authorize('dispute', $entry);

        $this->disputingEntryId = $entry->id;
        $this->disputeReason = '';
    }

    public function cancelDispute(): void
    {
        $this->disputingEntryId = null;
        $this->disputeReason = '';
    }

    public function dispute(int $entryId, DisputeTabEntry $action): void
    {
        $validated = $this->validate([
            'disputeReason' => 'nullable|string|max:255',
        ]);

        $reason = filled($validated['disputeReason'] ?? null) ? $validated['disputeReason'] : null;

        $this->runTransition($entryId, 'dispute', fn (TabEntry $entry) => $action->handle($entry, $reason));
        $this->cancelDispute();
    }

    public function withdraw(int $entryId, WithdrawTabEntry $action): void
    {
        $this->runTransition($entryId, 'withdraw', fn (TabEntry $entry) => $action->handle($entry));
    }

    public function askToCorrect(int $entryId): void
    {
        $entry = $this->tab->entries()->findOrFail($entryId);

        $this->authorize('correct', $entry);

        $this->correctingEntryId = $entry->id;
        $this->correctionAmount = $entry->amount;
        $this->correctionDescription = $entry->description;
    }

    public function cancelCorrection(): void
    {
        $this->correctingEntryId = null;
        $this->correctionAmount = '';
        $this->correctionDescription = '';
    }

    public function correct(CorrectDisputedTabEntry $action): void
    {
        if ($this->correctingEntryId === null) {
            return;
        }

        $entry = $this->tab->entries()->findOrFail($this->correctingEntryId);

        $this->authorize('correct', $entry);

        $validated = $this->validate([
            'correctionDescription' => 'required|string|max:120',
            'correctionAmount' => 'required|numeric|min:0.01|max:9999999999.99|decimal:0,2',
        ]);

        try {
            $action->handle($entry, auth()->user(), [
                'description' => $validated['correctionDescription'],
                'amount' => $validated['correctionAmount'],
            ]);
        } catch (InvalidTabEntryTransition|TabAgreementRequired|TabLimitExceeded $exception) {
            $this->addError('correctionAmount', $exception->userMessage());

            return;
        }

        $this->cancelCorrection();
    }

    public function confirmPending(ConfirmPendingTabEntries $action): void
    {
        $this->authorize('confirmPending', $this->tab);

        try {
            $action->handle($this->tab);
        } catch (TabAgreementRequired|TabLimitExceeded $exception) {
            $this->addError('entry', $exception->userMessage());
        }
    }

    public function askToSettle(): void
    {
        $this->authorize('settle', $this->tab);

        $this->settlementAmount = $this->tab->outstandingBalance();
        $this->confirmingSettlement = true;
    }

    public function cancelSettlement(): void
    {
        $this->confirmingSettlement = false;
    }

    public function settle(RequestTabSettlement $action): void
    {
        $this->authorize('settle', $this->tab);

        if ($this->settlementAmount === '') {
            $this->settlementAmount = $this->tab->outstandingBalance();
        }

        $this->validate([
            'settlementAmount' => 'required|numeric|min:0.01|max:9999999999.99|decimal:0,2',
        ]);

        try {
            $action->handle($this->tab, $this->settlementAmount);
        } catch (MomoRequestFailed $exception) {
            $this->addError('settlement', $exception->userMessage());
        }

        $this->confirmingSettlement = false;
    }

    /**
     * The other person changed something. The body is ignored on purpose: the
     * render pass refetches through this user's own policies, so nothing
     * arrives over the socket that they were not already allowed to see.
     */
    #[On('echo-private:tabs.{tab.id},TabUpdated')]
    public function tabChanged(): void
    {
        $this->tab->refresh();
        $this->fillTermsFromTab();
    }

    /**
     * Asks MoMo directly while a payment is awaiting approval. MoMo sends its
     * callback once and may never send it at all, so the screen asks rather
     * than trusting one to arrive. Only runs while a payment is in flight.
     */
    public function refreshPayment(ApplyMomoResult $action): void
    {
        $pending = $this->pendingPayment();

        if ($pending === null) {
            return;
        }

        try {
            $action->handle($pending);
        } catch (MomoRequestFailed) {
            // Leave it pending; the scheduled reconcile will catch up.
        }
    }

    public function refreshHybridPayment(ApplyMomoResult $action): void
    {
        $pending = $this->tab->paymentRequests()
            ->whereNotNull('checkout_id')
            ->awaitingFinalStatus()
            ->latest('id')
            ->first();

        if ($pending === null) {
            return;
        }

        try {
            $action->handle($pending);
        } catch (MomoRequestFailed) {
            // Leave it pending; the scheduled reconcile will catch up.
        }
    }

    public function pendingPayment(): ?PaymentRequest
    {
        return $this->tab->paymentRequests()
            ->forTabSettlement()
            ->awaitingFinalStatus()
            ->latest('id')
            ->first();
    }

    /**
     * Authorize against the entry, then run the transition, turning a lost race
     * into a readable message rather than a 500.
     */
    protected function runTransition(int $entryId, string $ability, callable $transition): void
    {
        $entry = $this->tab->entries()->findOrFail($entryId);

        $this->authorize($ability, $entry);

        try {
            $transition($entry);
        } catch (InvalidTabEntryTransition|TabAgreementRequired|TabLimitExceeded $exception) {
            $this->addError('entry', $exception->userMessage());
        }
    }

    protected function awaitingCheckout(): ?Checkout
    {
        return $this->tab->checkouts()
            ->where('status', CheckoutStatus::AwaitingConfirmation)
            ->latest('id')
            ->first();
    }

    protected function fillTermsFromTab(): void
    {
        if ($this->tab->limit_amount !== null) {
            $this->limitAmount = $this->tab->limit_amount;
        }

        if ($this->tab->settlement_day !== null) {
            $this->settlementDay = (string) $this->tab->settlement_day;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $user = auth()->user();

        $this->tab->loadMissing(['customer', 'merchant.merchantProfile']);

        $entries = $this->tab->entries()
            ->with(['creator', 'corrects', 'correction'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $pendingEntries = $entries->filter(fn (TabEntry $entry) => $entry->isAwaitingConfirmation());
        $pendingTotal = $pendingEntries->reduce(
            fn (string $total, TabEntry $entry) => bcadd($total, $entry->amount, 2),
            '0.00',
        );

        $paymentRequests = $this->tab->paymentRequests()
            ->forTabSettlement()
            ->latest('id')
            ->get();

        $entriesTotal = $entries
            ->filter(fn (TabEntry $entry) => $entry->status === TabEntryStatus::Confirmed && $entry->settled_at === null)
            ->reduce(fn (string $total, TabEntry $entry) => bcadd($total, $entry->amount, 2), '0.00');

        $unallocatedCredits = $paymentRequests
            ->filter(fn (PaymentRequest $payment) => $payment->status === PaymentRequestStatus::Successful)
            ->reduce(fn (string $total, PaymentRequest $payment) => bcadd($total, $payment->unallocated_amount, 2), '0.00');

        $awaitingCheckout = $this->tab->checkouts()
            ->where('status', CheckoutStatus::AwaitingConfirmation)
            ->with(['lines' => fn ($query) => $query->orderBy('id')])
            ->latest('id')
            ->first();

        $pendingHybrid = $this->tab->checkouts()
            ->where('status', CheckoutStatus::AwaitingMomo)
            ->where('tab_amount', '>', 0)
            ->with(['lines' => fn ($query) => $query->orderBy('id')])
            ->latest('id')
            ->first();

        return [
            'user' => $user,
            'isMerchant' => $this->tab->isMerchant($user),
            'awaitingCheckout' => $awaitingCheckout,
            'pendingHybrid' => $pendingHybrid,
            'entries' => $entries,
            'pendingEntries' => $pendingEntries,
            'pendingTotal' => $pendingTotal,
            'balance' => bcsub($entriesTotal, $unallocatedCredits, 2),
            'unallocatedCredits' => $unallocatedCredits,
            'pendingPayment' => $paymentRequests->first(fn (PaymentRequest $payment) => ! $payment->isFinal()),
            'lastPayment' => $paymentRequests->first(),
            'shelf' => $this->tab->isMerchant($user) && $this->addingEntry
                ? Product::query()
                    ->where('merchant_id', $this->tab->merchant_id)
                    ->active()
                    ->search($this->query)
                    ->orderBy('name')
                    ->orderBy('id')
                    ->get()
                : collect(),
        ];
    }
};
?>

<div class="space-y-5">
    @php
        $other = $isMerchant ? $tab->customer : $tab->merchant;
        $heading = $isMerchant
            ? $other->name
            : ($tab->merchant->merchantProfile?->business_name ?? $other->name);
    @endphp

    <div>
        <a href="{{ route('tabs') }}" wire:navigate class="text-xs font-semibold text-ink-500 hover:text-momo-800">
            &larr; All tabs
        </a>
    </div>

    <div class="overflow-hidden rounded-2xl bg-momo-900">
        <div class="px-5 py-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-momo-300">
                {{ $isMerchant ? 'Owed to you by' : 'You owe' }}
            </p>
            <p class="mt-1 text-sm font-semibold text-white">{{ $heading }}</p>
            <x-money :amount="$balance" class="mt-3 block text-4xl font-bold text-white" />
            @if ($tab->hasAcceptedAgreement())
                <p class="mt-2 text-xs font-medium text-momo-300">
                    Limit <x-money :amount="$tab->limit_amount" class="text-momo-200" />
                    · available <x-money :amount="$tab->availableAmount()" class="text-momo-200" />
                    · due {{ $tab->nextDueDate()?->format('j M') }}
                </p>
            @elseif ($tab->termsAreProposed())
                <p class="mt-2 text-xs font-medium text-sunshine-300">Limit and payday proposed — waiting for agreement</p>
            @endif
            @if (bccomp($unallocatedCredits, '0.00', 2) === 1)
                <p class="mt-2 text-xs font-medium text-momo-300">
                    <x-money :amount="$unallocatedCredits" class="text-momo-200" /> already paid, waiting to cover the oldest item in full.
                </p>
            @endif
        </div>
        <div class="h-1 bg-sunshine-400"></div>
    </div>

    @if (! $isMerchant && $tab->isDueSoon())
        <div class="rounded-xl border border-sunshine-300 bg-sunshine-50 px-4 py-4">
            <p class="text-sm font-bold text-ink-950">{{ $tab->paydayUrgencyLabel() }}</p>
            <p class="mt-1 text-sm text-ink-600">
                Your <x-money :amount="$balance" class="font-semibold" />
                TrustTab at {{ $heading }} is due {{ $tab->nextDueDate()?->isToday() ? 'today' : 'tomorrow' }}.
            </p>
        </div>
    @endif

    @error('entry')
        <p class="rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $message }}</p>
    @enderror

    @error('settlement')
        <p class="rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $message }}</p>
    @enderror

    @error('agreement')
        <p class="rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $message }}</p>
    @enderror

    @can('acceptAgreement', $tab)
        <div class="space-y-3 rounded-xl border border-sunshine-300 bg-sunshine-50 px-4 py-4">
            <p class="text-sm font-bold text-ink-950">Agree this tab?</p>
            <p class="text-sm text-ink-600">
                Limit
                <x-money :amount="$tab->limit_amount" class="font-semibold" />
                · payday the {{ $tab->settlementDayLabel() }} of each month
                · next due {{ $tab->nextDueDate()?->format('j M') }}.
            </p>
            <button type="button" wire:click="acceptAgreement"
                    class="w-full rounded-lg bg-sunshine-400 px-4 py-2.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                <span class="in-data-loading:hidden">Agree these terms</span>
                <span class="not-in-data-loading:hidden">Agreeing…</span>
            </button>
        </div>
    @endcan

    @can('proposeTerms', $tab)
        <form wire:submit="proposeTerms" class="space-y-3 rounded-xl border border-ink-200 p-4">
            <p class="text-sm font-bold text-ink-950">
                {{ $tab->hasAcceptedAgreement() ? 'Tab agreement' : 'Set a limit and payday' }}
            </p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="limitAmount" class="block text-xs font-semibold text-ink-600">Limit</label>
                    <input id="limitAmount" type="text" inputmode="decimal" wire:model="limitAmount"
                           class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm tabular-nums focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                    @error('limitAmount')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="settlementDay" class="block text-xs font-semibold text-ink-600">Payday (1–31)</label>
                    <input id="settlementDay" type="number" min="1" max="31" wire:model="settlementDay"
                           class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm tabular-nums focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                    @error('settlementDay')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
            <button type="submit"
                    class="w-full rounded-lg border border-ink-300 px-4 py-2.5 text-sm font-semibold text-ink-700 transition hover:bg-ink-50 data-loading:pointer-events-none data-loading:opacity-50">
                <span class="in-data-loading:hidden">{{ $tab->hasAcceptedAgreement() ? 'Update terms' : 'Propose terms' }}</span>
                <span class="not-in-data-loading:hidden">Saving…</span>
            </button>
            @if ($tab->termsAreProposed() && ! $tab->hasAcceptedAgreement())
                <p class="text-xs text-ink-500">{{ $other->name }} has to agree before you can add to this tab.</p>
            @endif
        </form>
    @endcan

    <div wire:loading.delay wire:target="settle">
        <x-skeleton.banner />
    </div>

    @if ($pendingPayment)
        {{-- Status is polled because MoMo may never call back. Sandbox often confirms immediately. --}}
        <div wire:poll.5s.visible="refreshPayment"
             class="flex items-center gap-3 rounded-xl border border-momo-200 bg-momo-50 px-4 py-3">
            <span class="size-2.5 shrink-0 animate-pulse rounded-full bg-sunshine-400"></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-momo-800">Waiting for MoMo</p>
                <p class="mt-0.5 text-xs text-ink-600">
                    Collecting <x-money :amount="$pendingPayment->amount" class="font-semibold" />.
                    Check your phone for a prompt. The tab settles when MoMo confirms.
                </p>
            </div>
        </div>
    @elseif ($lastPayment?->status === App\Enums\PaymentRequestStatus::Successful)
        <div class="rounded-xl border border-momo-200 bg-momo-50 px-4 py-3">
            <p class="text-sm font-semibold text-momo-800">Paid with MoMo</p>
            <p class="mt-0.5 text-xs text-ink-600">
                <x-money :amount="$lastPayment->amount" class="font-semibold" />
                settled {{ $lastPayment->completed_at?->diffForHumans() }}.
            </p>
        </div>
    @elseif ($lastPayment?->status === App\Enums\PaymentRequestStatus::Failed && ! $errors->has('settlement'))
        {{-- Same record for both people: confirming Pay only sends the prompt. --}}
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
            <p class="text-sm font-semibold text-red-700">That payment did not go through</p>
            <p class="mt-0.5 text-xs text-red-600">
                {{ $lastPayment->failureMessage() ?? 'Nothing was taken and the tab is unchanged. You can try again.' }}
            </p>
            @if ($lastPayment->failureMessage())
                <p class="mt-0.5 text-xs text-red-600">Nothing was taken and the tab is unchanged. You can try again.</p>
            @endif
        </div>
    @endif

    @can('settle', $tab)
        @unless ($pendingPayment)
            <button wire:click="askToSettle"
                    class="flex w-full items-center justify-between gap-3 rounded-xl bg-sunshine-400 px-4 py-3 text-left transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                <span>
                    <span class="block text-sm font-bold text-black">Pay with MoMo</span>
                    <span class="block text-xs text-black/70">Pay some or all of the confirmed items</span>
                </span>
                <x-money :amount="$balance" class="text-base font-bold text-black" />
            </button>
        @endunless
    @endcan

    @can('runTill', $tab)
        <a href="{{ route('tabs.till', $tab) }}" wire:navigate
           class="flex w-full items-center justify-center rounded-xl bg-sunshine-400 px-4 py-3 text-sm font-bold text-black transition hover:bg-sunshine-500">
            Till
        </a>
    @endcan

    @if ($pendingHybrid)
        <div wire:poll.5s.visible="refreshHybridPayment"
             class="space-y-2 rounded-xl border border-momo-200 bg-momo-50 px-4 py-4">
            <p class="text-sm font-bold text-momo-800">Waiting for MoMo</p>
            <p class="text-sm text-ink-600">
                Collecting
                <x-money :amount="$pendingHybrid->momo_amount" class="font-semibold" />.
                <x-money :amount="$pendingHybrid->tab_amount" class="font-semibold" />
                goes on the tab when MoMo confirms. Stock stays until then.
            </p>
        </div>
    @endif

    @if ($awaitingCheckout)
        <div class="space-y-3 rounded-xl border border-sunshine-300 bg-sunshine-50 px-4 py-4">
            <p class="text-sm font-bold text-ink-950">
                @if ($isMerchant)
                    Waiting for {{ $tab->customer->name }} to confirm this basket
                @elseif ($awaitingCheckout->isHybrid())
                    Pay some now, the rest on your TrustTab
                @else
                    This will be added to your TrustTab
                @endif
            </p>
            @if ($awaitingCheckout->isHybrid())
                <p class="text-sm text-ink-600">
                    MoMo will collect
                    <x-money :amount="$awaitingCheckout->momo_amount" class="font-semibold" />.
                    <x-money :amount="$awaitingCheckout->tab_amount" class="font-semibold" />
                    will go on the tab after that. Stock stays until both succeed.
                </p>
            @endif
            <div class="space-y-2">
                @foreach ($awaitingCheckout->lines as $line)
                    <div wire:key="awaiting-line-{{ $line->id }}" class="flex items-start justify-between gap-3">
                        <p class="min-w-0 truncate text-sm text-ink-700">
                            {{ $line->name }}
                            <span class="text-xs text-ink-500">× {{ $line->quantity }}</span>
                        </p>
                        <x-money :amount="$line->lineTotal()" class="text-sm font-semibold" />
                    </div>
                @endforeach
            </div>
            <div class="flex items-center justify-between border-t border-sunshine-200 pt-3">
                <p class="text-sm font-semibold text-ink-950">Total</p>
                <x-money :amount="$awaitingCheckout->total()" class="text-base font-bold text-ink-950" />
            </div>
            @canany(['confirm', 'dispute'], $awaitingCheckout)
                <div class="flex gap-2">
                    @can('confirm', $awaitingCheckout)
                        <button type="button" wire:click="confirmCheckout"
                                class="flex-1 rounded-lg bg-sunshine-400 px-3 py-2.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                            <span class="in-data-loading:hidden">Confirm <x-money :amount="$awaitingCheckout->total()" /></span>
                            <span class="not-in-data-loading:hidden">Confirming…</span>
                        </button>
                    @endcan
                    @can('dispute', $awaitingCheckout)
                        <button type="button" wire:click="disputeCheckout"
                                class="flex-1 rounded-lg border border-ink-300 bg-white px-3 py-2.5 text-sm font-semibold text-ink-700 transition hover:bg-ink-50 data-loading:pointer-events-none data-loading:opacity-50">
                            Not these
                        </button>
                    @endcan
                </div>
            @endcanany
        </div>
    @endif

    @can('addEntry', $tab)
        <button type="button" wire:click="askToAddEntry"
                class="w-full rounded-xl border border-ink-300 px-4 py-3 text-sm font-semibold text-ink-700 transition hover:bg-ink-50">
            Add to tab
        </button>
    @endcan

    <div class="space-y-2">
        <p class="text-sm font-bold">Items</p>

        @if ($pendingEntries->count() > 1)
            @can('confirmPending', $tab)
                <div class="rounded-xl border border-sunshine-300 bg-sunshine-50 px-4 py-4">
                    <p class="text-sm font-bold text-ink-950">
                        {{ $pendingEntries->every(fn (TabEntry $entry) => $entry->created_at->isToday()) ? 'Purchases today' : 'Waiting for you' }}
                    </p>
                    <p class="mt-1 text-sm text-ink-600">
                        {{ $pendingEntries->count() }} items
                        · <x-money :amount="$pendingTotal" class="font-semibold" />
                    </p>
                    <button type="button" wire:click="confirmPending"
                            class="mt-3 w-full rounded-lg bg-sunshine-400 px-4 py-2.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                        <span class="in-data-loading:hidden">Confirm these <x-money :amount="$pendingTotal" /></span>
                        <span class="not-in-data-loading:hidden">Confirming…</span>
                    </button>
                    <p class="mt-2 text-xs text-ink-500">You can still open any line and say it is not yours.</p>
                </div>
            @endcan
        @endif

        @forelse ($entries as $entry)
            <div wire:key="entry-{{ $entry->id }}"
                 class="rounded-xl border border-ink-200 px-4 py-3 {{ $entry->status->isFinal() ? 'opacity-60' : '' }}">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-ink-950">{{ $entry->description }}</p>
                        <p class="mt-0.5 text-xs text-ink-500">
                            {{ $entry->created_at->diffForHumans() }}
                            &middot;
                            <span @class([
                                'font-semibold',
                                'text-sunshine-700' => $entry->status === TabEntryStatus::PendingConfirmation,
                                'text-momo-800' => $entry->status === TabEntryStatus::Confirmed,
                                'text-red-700' => $entry->status === TabEntryStatus::Disputed,
                                'text-ink-500' => $entry->status->isFinal(),
                            ])>{{ $entry->status->label() }}</span>
                        </p>
                        @if ($entry->corrects)
                            <p class="mt-1 text-xs text-ink-500">
                                Correction of {{ $entry->corrects->description }}
                                (was <x-money :amount="$entry->corrects->amount" class="font-semibold" />)
                            </p>
                        @endif
                        @if ($entry->correction)
                            <p class="mt-1 text-xs text-ink-500">
                                Corrected to {{ $entry->correction->description }}
                                <x-money :amount="$entry->correction->amount" class="font-semibold" />
                            </p>
                        @endif
                        @if ($entry->note)
                            <p class="mt-1 text-xs {{ $entry->status === TabEntryStatus::Disputed ? 'text-red-700' : 'text-ink-500' }}">
                                {{ $entry->status === TabEntryStatus::Disputed ? 'Reason: ' : '' }}{{ $entry->note }}
                            </p>
                        @endif
                    </div>
                    <x-money :amount="$entry->amount" class="text-sm font-bold" />
                </div>

                @canany(['confirm', 'dispute', 'withdraw', 'correct'], $entry)
                    <div class="mt-3 flex flex-wrap gap-2">
                        @can('confirm', $entry)
                            <button wire:click="confirm({{ $entry->id }})"
                                    class="flex-1 rounded-lg bg-sunshine-400 px-3 py-2 text-xs font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                                <span class="in-data-loading:hidden">Yes, that was me</span>
                                <span class="not-in-data-loading:hidden">Confirming…</span>
                            </button>
                        @endcan

                        @can('dispute', $entry)
                            <button wire:click="askToDispute({{ $entry->id }})"
                                    class="flex-1 rounded-lg border border-ink-300 px-3 py-2 text-xs font-semibold text-ink-700 transition hover:bg-ink-50 data-loading:pointer-events-none data-loading:opacity-50">
                                That is not mine
                            </button>
                        @endcan

                        @can('correct', $entry)
                            <button wire:click="askToCorrect({{ $entry->id }})"
                                    class="flex-1 rounded-lg bg-sunshine-400 px-3 py-2 text-xs font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                                Post the right amount
                            </button>
                        @endcan

                        @can('withdraw', $entry)
                            <button wire:click="withdraw({{ $entry->id }})"
                                    class="flex-1 rounded-lg border border-ink-300 px-3 py-2 text-xs font-semibold text-ink-700 transition hover:bg-ink-50 data-loading:pointer-events-none data-loading:opacity-50">
                                Remove it
                            </button>
                        @endcan
                    </div>
                @endcanany
            </div>
        @empty
            <p class="rounded-xl bg-ink-50 px-4 py-10 text-center text-sm text-ink-500">
                Nothing on this tab yet.
            </p>
        @endforelse
    </div>

    @if ($confirmingSettlement)
        @teleport('body')
            <div wire:click="cancelSettlement"
                 x-data
                 x-on:keydown.escape.window="$wire.cancelSettlement()"
                 class="fixed inset-0 z-50 flex items-end justify-center bg-momo-950/60 p-4 sm:items-center"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="settle-title">
                <div wire:click.stop class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg">
                    <p id="settle-title" class="text-base font-bold text-ink-950">Pay this tab?</p>
                    <p class="mt-1 text-sm text-ink-600">
                        MoMo will collect this amount from
                        <span class="font-semibold tabular-nums">{{ Msisdn::forDisplay($tab->customer->msisdn) }}</span>.
                        Check your phone for a prompt. The tab settles when MoMo confirms.
                    </p>
                    <div class="mt-4">
                        <label for="settlementAmount" class="block text-xs font-semibold text-ink-600">Amount</label>
                        <input id="settlementAmount" type="text" inputmode="decimal" wire:model="settlementAmount"
                               class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm tabular-nums focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                        <p class="mt-1 text-xs text-ink-500">
                            Outstanding <x-money :amount="$balance" class="font-semibold" />. You can pay some or all of it.
                        </p>
                        @error('settlementAmount')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="mt-5 flex gap-2">
                        <button type="button" wire:click="cancelSettlement" wire:loading.attr="disabled" wire:target="settle"
                                class="flex-1 rounded-lg border border-ink-300 px-3 py-2.5 text-sm font-semibold text-ink-700 transition hover:bg-ink-50">
                            Not now
                        </button>
                        <button type="button" wire:click="settle" wire:loading.attr="disabled" wire:target="settle"
                                class="flex-1 rounded-lg bg-sunshine-400 px-3 py-2.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                            <span wire:loading.remove wire:target="settle">Pay now</span>
                            <span wire:loading wire:target="settle">Sending…</span>
                        </button>
                    </div>
                </div>
            </div>
        @endteleport
    @endif

    @if ($disputingEntryId)
        @teleport('body')
            <div wire:click="cancelDispute"
                 x-data
                 x-on:keydown.escape.window="$wire.cancelDispute()"
                 class="fixed inset-0 z-50 flex items-end justify-center bg-momo-950/60 p-4 sm:items-center"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="dispute-title">
                <div wire:click.stop class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg">
                    <p id="dispute-title" class="text-base font-bold text-ink-950">Not this item?</p>
                    <p class="mt-1 text-sm text-ink-600">It comes off the balance. You can say why, so the shop can post the right amount.</p>
                    <div class="mt-4">
                        <label for="disputeReason" class="block text-xs font-semibold text-ink-600">Reason (optional)</label>
                        <input id="disputeReason" type="text" wire:model="disputeReason"
                               class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                        @error('disputeReason')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="mt-5 flex gap-2">
                        <button type="button" wire:click="cancelDispute"
                                class="flex-1 rounded-lg border border-ink-300 px-3 py-2.5 text-sm font-semibold text-ink-700 transition hover:bg-ink-50">
                            Keep it
                        </button>
                        <button type="button" wire:click="dispute({{ $disputingEntryId }})"
                                class="flex-1 rounded-lg bg-sunshine-400 px-3 py-2.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                            <span class="in-data-loading:hidden">That is not mine</span>
                            <span class="not-in-data-loading:hidden">Saving…</span>
                        </button>
                    </div>
                </div>
            </div>
        @endteleport
    @endif

    @if ($addingEntry)
        @teleport('body')
            <div wire:click="cancelAddEntry"
                 x-data
                 x-on:keydown.escape.window="$wire.cancelAddEntry()"
                 class="fixed inset-0 z-50 flex items-center justify-center bg-momo-950/60 p-4"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="add-entry-title">
                <form wire:click.stop wire:submit="addEntry" class="max-h-[90vh] w-full max-w-sm overflow-y-auto rounded-2xl bg-white p-5 shadow-lg">
                    <p id="add-entry-title" class="text-base font-bold text-ink-950">Add to tab</p>
                    <p class="mt-1 text-sm text-ink-600">
                        Pick from the shelf, or type something that is not listed.
                        {{ $other->name }} has to confirm it. Stock is counted on the till.
                    </p>

                    <div class="mt-4">
                        <label for="shelfSearch" class="block text-xs font-semibold text-ink-600">Search the shelf</label>
                        <input id="shelfSearch" type="search" autocomplete="off" wire:model.live.debounce.250ms="query"
                               placeholder="Name or barcode" autofocus
                               class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        @forelse ($shelf as $product)
                            <button type="button" wire:key="shelf-{{ $product->id }}" wire:click="selectProduct({{ $product->id }})"
                                    @class([
                                        'rounded-xl border px-3 py-3 text-left transition hover:bg-ink-50',
                                        'border-momo-800 bg-momo-50' => $description === $product->name,
                                        'border-ink-200' => $description !== $product->name,
                                    ])>
                                <p class="truncate text-sm font-semibold text-ink-950">{{ $product->name }}</p>
                                <x-money :amount="$product->selling_price" class="mt-1 block text-sm font-bold" />
                            </button>
                        @empty
                            <p class="col-span-2 rounded-xl bg-ink-50 px-4 py-6 text-center text-sm text-ink-500">
                                {{ filled($query) ? 'Nothing matches that name.' : 'Nothing on the shelf yet. Type what they took.' }}
                            </p>
                        @endforelse
                    </div>

                    <div class="mt-4 space-y-3">
                        <div>
                            <label for="description" class="block text-xs font-semibold text-ink-600">What did they take?</label>
                            <input id="description" type="text" wire:model="description" placeholder="e.g. paraffin"
                                   class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                            @error('description')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="amount" class="block text-xs font-semibold text-ink-600">Amount</label>
                            <input id="amount" type="text" inputmode="decimal" wire:model="amount" placeholder="45.50"
                                   class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm tabular-nums focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                            @error('amount')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-5 flex gap-2">
                        <button type="button" wire:click="cancelAddEntry" wire:loading.attr="disabled" wire:target="addEntry"
                                class="flex-1 rounded-lg border border-ink-300 px-3 py-2.5 text-sm font-semibold text-ink-700 transition hover:bg-ink-50">
                            Not now
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="addEntry"
                                class="flex-1 rounded-lg bg-sunshine-400 px-3 py-2.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                            <span class="in-data-loading:hidden">Add to tab</span>
                            <span class="not-in-data-loading:hidden">Adding…</span>
                        </button>
                    </div>
                </form>
            </div>
        @endteleport
    @endif

    @if ($correctingEntryId)
        @teleport('body')
            <div wire:click="cancelCorrection"
                 x-data
                 x-on:keydown.escape.window="$wire.cancelCorrection()"
                 class="fixed inset-0 z-50 flex items-end justify-center bg-momo-950/60 p-4 sm:items-center"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="correct-title">
                <div wire:click.stop class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg">
                    <p id="correct-title" class="text-base font-bold text-ink-950">Post the right amount</p>
                    <p class="mt-1 text-sm text-ink-600">The disputed line stays on the ledger as written. This adds a new line they have to confirm.</p>
                    <div class="mt-4 space-y-3">
                        <div>
                            <label for="correctionDescription" class="block text-xs font-semibold text-ink-600">What it was</label>
                            <input id="correctionDescription" type="text" wire:model="correctionDescription"
                                   class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                            @error('correctionDescription')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="correctionAmount" class="block text-xs font-semibold text-ink-600">Amount</label>
                            <input id="correctionAmount" type="text" inputmode="decimal" wire:model="correctionAmount"
                                   class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm tabular-nums focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                            @error('correctionAmount')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="mt-5 flex gap-2">
                        <button type="button" wire:click="cancelCorrection"
                                class="flex-1 rounded-lg border border-ink-300 px-3 py-2.5 text-sm font-semibold text-ink-700 transition hover:bg-ink-50">
                            Not now
                        </button>
                        <button type="button" wire:click="correct"
                                class="flex-1 rounded-lg bg-sunshine-400 px-3 py-2.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                            <span class="in-data-loading:hidden">Post correction</span>
                            <span class="not-in-data-loading:hidden">Saving…</span>
                        </button>
                    </div>
                </div>
            </div>
        @endteleport
    @endif
</div>
