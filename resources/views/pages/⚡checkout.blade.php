<?php

use App\Actions\AddProductToBasket;
use App\Actions\AdjustBasketLine;
use App\Actions\ApplyMomoResult;
use App\Actions\CancelOpenCheckout;
use App\Actions\RequestCheckoutPayNow;
use App\Actions\RequestHybridCheckout;
use App\Actions\RequestTrustTabCheckout;
use App\Actions\SaveProduct;
use App\Enums\CheckoutStatus;
use App\Enums\PaymentRequestStatus;
use App\Exceptions\CheckoutAwaitingConfirmation;
use App\Exceptions\CheckoutAwaitingMomo;
use App\Exceptions\EmptyBasket;
use App\Exceptions\InvalidHybridSplit;
use App\Exceptions\MomoRequestFailed;
use App\Exceptions\NotEnoughStock;
use App\Exceptions\ProductNotOnShelf;
use App\Exceptions\TabAgreementRequired;
use App\Exceptions\TabLimitExceeded;
use App\Models\CheckoutLine;
use App\Models\PaymentRequest;
use App\Models\Product;
use App\Models\Tab;
use App\Support\Msisdn;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Till')]
class extends Component
{
    public Tab $tab;

    public string $barcode = '';

    public string $query = '';

    public ?string $scanStatus = null;

    public bool $addingProduct = false;

    public string $name = '';

    public string $sellingPrice = '';

    public string $stockQuantity = '1';

    public bool $confirmingPayNow = false;

    public bool $confirmingTrustTab = false;

    public bool $confirmingSplit = false;

    public string $momoAmount = '';

    public function mount(Tab $tab): void
    {
        $this->authorize('runTill', $tab);

        $this->tab = $tab;
    }

    public function addProduct(int $productId, AddProductToBasket $action): void
    {
        $this->authorize('runTill', $this->tab);

        $product = $this->ownedProduct($productId);

        $this->putOnBasket($action, $product);
    }

    public function addByBarcode(string $code, AddProductToBasket $action): void
    {
        $this->barcode = trim($code);
        $this->lookUpBarcode($action);
    }

    public function lookUpBarcode(AddProductToBasket $action): void
    {
        $this->authorize('runTill', $this->tab);

        $validated = $this->validate([
            'barcode' => 'required|string|max:64',
        ]);

        $barcode = trim($validated['barcode']);
        $this->barcode = $barcode;
        $this->scanStatus = null;

        $product = Product::query()
            ->where('merchant_id', $this->tab->merchant_id)
            ->where('barcode', $barcode)
            ->first();

        if ($product === null) {
            $this->scanStatus = 'missing';

            return;
        }

        $this->putOnBasket($action, $product);
    }

    public function askToAddProduct(): void
    {
        $this->authorize('create', Product::class);
        $this->authorize('runTill', $this->tab);

        $this->name = '';
        $this->sellingPrice = '';
        $this->stockQuantity = '1';
        $this->addingProduct = true;
        $this->resetValidation();
    }

    public function askToAddWithoutBarcode(): void
    {
        $this->barcode = '';
        $this->scanStatus = null;
        $this->askToAddProduct();
        $this->name = trim($this->query);
    }

    public function cancelAddProduct(): void
    {
        $this->addingProduct = false;
        $this->name = '';
        $this->sellingPrice = '';
        $this->stockQuantity = '1';
        $this->resetValidation();
    }

    public function addNewProduct(SaveProduct $save, AddProductToBasket $basket): void
    {
        $this->authorize('create', Product::class);
        $this->authorize('runTill', $this->tab);

        if (trim($this->stockQuantity) === '') {
            $this->stockQuantity = '1';
        }

        $validated = $this->validate([
            'name' => 'required|string|max:120',
            'barcode' => 'nullable|string|max:64',
            'sellingPrice' => 'required|numeric|min:0.01|max:9999999999.99|decimal:0,2',
            'stockQuantity' => 'required|integer|min:0|max:999999',
        ]);

        $product = $save->handle(auth()->user(), [
            'name' => $validated['name'],
            'barcode' => $validated['barcode'] ?: null,
            'selling_price' => $validated['sellingPrice'],
            'stock_quantity' => (int) $validated['stockQuantity'],
            'low_stock_threshold' => 0,
        ]);

        $this->addingProduct = false;
        $this->name = '';
        $this->sellingPrice = '';
        $this->stockQuantity = '1';
        $this->putOnBasket($basket, $product);
    }

    public function setLineQuantity(int $lineId, int $quantity, AdjustBasketLine $action): void
    {
        $line = $this->ownedLine($lineId);

        $this->authorize('update', $line->checkout);

        try {
            $action->handle($line, $quantity);
        } catch (NotEnoughStock $exception) {
            $this->addError('basket', $exception->userMessage());
        }
    }

    public function removeLine(int $lineId, AdjustBasketLine $action): void
    {
        $this->setLineQuantity($lineId, 0, $action);
    }

    public function clearBasket(CancelOpenCheckout $action): void
    {
        $checkout = $this->tab->currentTillCheckout();

        if ($checkout === null) {
            return;
        }

        $this->authorize('cancel', $checkout);
        $action->handle($checkout);
        $this->scanStatus = null;
        $this->resetValidation();
    }

    public function askToPayNow(): void
    {
        $checkout = $this->tab->openCheckout();

        if ($checkout === null) {
            return;
        }

        $this->authorize('collect', $checkout);
        $this->confirmingPayNow = true;
    }

    public function cancelPayNow(): void
    {
        $this->confirmingPayNow = false;
    }

    public function askToPutOnTab(): void
    {
        $checkout = $this->tab->openCheckout();

        if ($checkout === null) {
            return;
        }

        $this->authorize('putOnTab', $checkout);
        $this->confirmingTrustTab = true;
    }

    public function cancelPutOnTab(): void
    {
        $this->confirmingTrustTab = false;
    }

    public function askToSplit(): void
    {
        $checkout = $this->tab->openCheckout();

        if ($checkout === null) {
            return;
        }

        $this->authorize('putOnTab', $checkout);
        $this->momoAmount = '';
        $this->confirmingSplit = true;
    }

    public function cancelSplit(): void
    {
        $this->confirmingSplit = false;
    }

    public function split(RequestHybridCheckout $action): void
    {
        $checkout = $this->tab->openCheckout();

        if ($checkout === null) {
            return;
        }

        $this->authorize('putOnTab', $checkout);

        $validated = $this->validate([
            'momoAmount' => 'required|numeric|min:0.01|max:9999999999.99|decimal:0,2',
        ]);

        try {
            $action->handle($checkout, $validated['momoAmount']);
        } catch (EmptyBasket|InvalidHybridSplit|TabAgreementRequired|TabLimitExceeded $exception) {
            $this->addError('split', $exception->userMessage());
        }

        $this->confirmingSplit = false;
    }

    public function putOnTab(RequestTrustTabCheckout $action): void
    {
        $checkout = $this->tab->currentTillCheckout();

        if ($checkout === null) {
            return;
        }

        $this->authorize('putOnTab', $checkout);

        try {
            $action->handle($checkout);
        } catch (EmptyBasket|TabAgreementRequired|TabLimitExceeded $exception) {
            $this->addError('trustTab', $exception->userMessage());
        }

        $this->confirmingTrustTab = false;
    }

    #[On('echo-private:tabs.{tab.id},TabUpdated')]
    public function tabChanged(): void
    {
        $this->tab->refresh();
    }

    public function payNow(RequestCheckoutPayNow $action): void
    {
        $checkout = $this->tab->currentTillCheckout();

        if ($checkout === null) {
            return;
        }

        $this->authorize('collect', $checkout);

        try {
            $action->handle($checkout);
        } catch (EmptyBasket|MomoRequestFailed $exception) {
            $this->addError('payNow', $exception->userMessage());
        }

        $this->confirmingPayNow = false;
    }

    public function refreshPayment(ApplyMomoResult $action): void
    {
        $pending = $this->pendingTillPayment();

        if ($pending === null) {
            return;
        }

        try {
            $action->handle($pending);
        } catch (MomoRequestFailed) {
            // Leave it pending; the scheduled reconcile will catch up.
        }
    }

    public function pendingTillPayment(): ?PaymentRequest
    {
        return $this->tab->paymentRequests()
            ->whereNotNull('checkout_id')
            ->awaitingFinalStatus()
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $this->tab->loadMissing(['customer', 'merchant.merchantProfile']);

        $checkout = $this->tab->checkouts()
            ->whereIn('status', [
                CheckoutStatus::Open,
                CheckoutStatus::AwaitingMomo,
                CheckoutStatus::AwaitingConfirmation,
            ])
            ->with(['lines' => fn ($query) => $query->orderBy('id')])
            ->latest('id')
            ->first();

        $tillPayments = $this->tab->paymentRequests()
            ->whereNotNull('checkout_id')
            ->latest('id')
            ->get();

        $basketTotal = $checkout?->total() ?? '0.00';
        $splitRemainder = is_numeric($this->momoAmount)
            ? bcsub($basketTotal, number_format((float) $this->momoAmount, 2, '.', ''), 2)
            : $basketTotal;

        return [
            'checkout' => $checkout,
            'lines' => $checkout?->lines ?? collect(),
            'basketTotal' => $basketTotal,
            'splitRemainder' => $splitRemainder,
            'awaitingMomo' => $checkout?->isAwaitingMomo() ?? false,
            'awaitingConfirmation' => $checkout?->isAwaitingConfirmation() ?? false,
            'basketFrozen' => $checkout?->isAwaitingMomo() || $checkout?->isAwaitingConfirmation(),
            'pendingPayment' => $tillPayments->first(fn (PaymentRequest $payment) => ! $payment->isFinal()),
            'lastPayment' => $tillPayments->first(),
            'products' => Product::query()
                ->where('merchant_id', $this->tab->merchant_id)
                ->active()
                ->search($this->query)
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
        ];
    }

    protected function putOnBasket(AddProductToBasket $action, Product $product): void
    {
        try {
            $action->handle($this->tab, $product);
            $this->scanStatus = 'added';
            $this->resetValidation();
        } catch (ProductNotOnShelf|NotEnoughStock|CheckoutAwaitingMomo|CheckoutAwaitingConfirmation $exception) {
            $this->scanStatus = null;
            $this->addError('basket', $exception->userMessage());
        }
    }

    protected function ownedProduct(int $productId): Product
    {
        return Product::query()
            ->where('merchant_id', $this->tab->merchant_id)
            ->whereKey($productId)
            ->first() ?? abort(404);
    }

    protected function ownedLine(int $lineId): CheckoutLine
    {
        $checkout = $this->tab->openCheckout();

        if ($checkout === null) {
            abort(404);
        }

        return $checkout->lines()->whereKey($lineId)->first() ?? abort(404);
    }
};
?>

<div class="space-y-5">
    <div>
        <a href="{{ route('tabs.show', $tab) }}" wire:navigate class="text-xs font-semibold text-ink-500 hover:text-momo-800">
            &larr; {{ $tab->customer->name }}
        </a>
    </div>

    <div class="overflow-hidden rounded-2xl bg-momo-900">
        <div class="px-5 py-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-momo-300">Till for</p>
            <p class="mt-1 text-sm font-semibold text-white">{{ $tab->customer->name }}</p>
            <x-money :amount="$tab->outstandingBalance()" class="mt-3 block text-4xl font-bold text-white" />
            @if ($tab->hasAcceptedAgreement())
                <p class="mt-2 text-xs font-medium text-momo-300">
                    Limit <x-money :amount="$tab->limit_amount" class="text-momo-200" />
                    · available <x-money :amount="$tab->availableAmount()" class="text-momo-200" />
                    · due {{ $tab->nextDueDate()?->format('j M') }}
                </p>
            @endif
        </div>
        <div class="h-1 bg-sunshine-400"></div>
    </div>

    @error('basket')
        <p class="rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $message }}</p>
    @enderror

    @error('payNow')
        <p class="rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $message }}</p>
    @enderror

    @error('trustTab')
        <p class="rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $message }}</p>
    @enderror

    @error('split')
        <p class="rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $message }}</p>
    @enderror

    <div wire:loading.delay wire:target="payNow">
        <x-skeleton.banner />
    </div>

    @if ($awaitingConfirmation)
        <div class="flex items-center gap-3 rounded-xl border border-sunshine-300 bg-sunshine-50 px-4 py-3">
            <span class="size-2.5 shrink-0 animate-pulse rounded-full bg-sunshine-400"></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-ink-950">Waiting for {{ $tab->customer->name }} to confirm</p>
                <p class="mt-0.5 text-xs text-ink-600">
                    @if ($checkout?->isHybrid())
                        MoMo will collect
                        <x-money :amount="$checkout->momo_amount" class="font-semibold" />
                        after they accept.
                        <x-money :amount="$checkout->tab_amount" class="font-semibold" />
                        stays off the tab until both succeed.
                    @else
                        <x-money :amount="$basketTotal" class="font-semibold" />
                        stays off the tab until they accept the basket. Stock stays on the shelf.
                    @endif
                </p>
            </div>
        </div>
    @elseif ($pendingPayment)
        <div wire:poll.5s.visible="refreshPayment"
             class="flex items-center gap-3 rounded-xl border border-momo-200 bg-momo-50 px-4 py-3">
            <span class="size-2.5 shrink-0 animate-pulse rounded-full bg-sunshine-400"></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-momo-800">Waiting for MoMo</p>
                <p class="mt-0.5 text-xs text-ink-600">
                    Collecting <x-money :amount="$pendingPayment->amount" class="font-semibold" />.
                    @if ($checkout?->isHybrid())
                        <x-money :amount="$checkout->tab_amount" class="font-semibold" />
                        goes on the tab when MoMo confirms. Stock stays until then.
                    @else
                        Check your phone for a prompt. Stock stays on the shelf until MoMo confirms.
                    @endif
                </p>
            </div>
        </div>
    @elseif ($lastPayment?->status === PaymentRequestStatus::Successful)
        <div class="rounded-xl border border-momo-200 bg-momo-50 px-4 py-3">
            <p class="text-sm font-semibold text-momo-800">Paid with MoMo</p>
            <p class="mt-0.5 text-xs text-ink-600">
                <x-money :amount="$lastPayment->amount" class="font-semibold" />
                collected {{ $lastPayment->completed_at?->diffForHumans() }}. Nothing went on the tab.
            </p>
        </div>
    @elseif ($lastPayment?->status === PaymentRequestStatus::Failed && ! $errors->has('payNow'))
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
            <p class="text-sm font-semibold text-red-700">That payment did not go through</p>
            <p class="mt-0.5 text-xs text-red-600">
                {{ $lastPayment->failureMessage() ?? 'Nothing was taken and the basket is unchanged. You can try again.' }}
            </p>
            @if ($lastPayment->failureMessage())
                <p class="mt-0.5 text-xs text-red-600">Nothing was taken and the basket is unchanged. You can try again.</p>
            @endif
        </div>
    @endif

    <div class="space-y-3 rounded-xl border border-ink-200 p-4">
        <p class="text-sm font-bold text-ink-950">Scan or look up</p>
        <div>
            <label for="tillBarcode" class="block text-xs font-semibold text-ink-600">Barcode</label>
            <input id="tillBarcode" type="text" inputmode="numeric" autocomplete="off" wire:model="barcode"
                   wire:keydown.enter.stop.prevent="lookUpBarcode"
                   @disabled($basketFrozen)
                   class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm tabular-nums focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
            <div class="mt-2 flex gap-2">
                <button type="button" data-barcode-scan data-barcode-action="addByBarcode"
                        @disabled($basketFrozen)
                        class="flex-1 rounded-xl border border-ink-300 px-4 py-3 text-sm font-semibold text-ink-700 transition hover:bg-ink-50 disabled:opacity-50">
                    Scan
                </button>
                <button type="button" wire:click="lookUpBarcode"
                        @disabled($basketFrozen)
                        class="flex-1 rounded-xl border border-ink-300 px-4 py-3 text-sm font-semibold text-ink-700 transition hover:bg-ink-50 data-loading:pointer-events-none data-loading:opacity-50 disabled:opacity-50">
                    <span class="in-data-loading:hidden">Look up</span>
                    <span class="not-in-data-loading:hidden">Adding…</span>
                </button>
            </div>
            @error('barcode')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
            @if ($scanStatus === 'missing')
                <div class="mt-2 flex items-center justify-between gap-3">
                    <p class="text-xs font-medium text-ink-600">Not in your book yet.</p>
                    <button type="button" wire:click="askToAddProduct"
                            @disabled($basketFrozen)
                            class="shrink-0 text-xs font-semibold text-momo-800 hover:text-momo-900 disabled:opacity-50">
                        Add it
                    </button>
                </div>
            @endif
            <p data-barcode-error hidden class="mt-1 text-xs font-medium text-red-600"></p>
        </div>
        <p class="text-xs text-ink-500">Stock stays on the shelf until they pay or confirm.</p>
    </div>

    <div class="space-y-2">
        <div class="flex items-center justify-between gap-3">
            <p class="text-sm font-bold">On the shelf</p>
            <button type="button" wire:click="askToAddWithoutBarcode"
                    @disabled($basketFrozen)
                    class="text-xs font-semibold text-momo-800 hover:text-momo-900 disabled:opacity-50">
                Add without a barcode
            </button>
        </div>
        <div>
            <label for="tillSearch" class="sr-only">Search the shelf</label>
            <input id="tillSearch" type="search" autocomplete="off" wire:model.live.debounce.250ms="query"
                   placeholder="Search by name or barcode"
                   class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
        </div>
        <div class="grid grid-cols-2 gap-2">
            @forelse ($products as $product)
                <button type="button" wire:key="product-{{ $product->id }}" wire:click="addProduct({{ $product->id }})"
                        @disabled($basketFrozen)
                        class="rounded-xl border border-ink-200 px-3 py-3 text-left transition hover:bg-ink-50 data-loading:pointer-events-none data-loading:opacity-50 disabled:opacity-50">
                    <p class="truncate text-sm font-semibold text-ink-950">{{ $product->name }}</p>
                    <x-money :amount="$product->selling_price" class="mt-1 block text-sm font-bold" />
                    <p class="mt-1 text-xs text-ink-500">{{ $product->stock_quantity }} on the shelf</p>
                </button>
            @empty
                <div class="col-span-2 rounded-xl bg-ink-50 px-4 py-10 text-center">
                    <p class="text-sm text-ink-500">
                        {{ filled($query) ? 'Nothing matches that name.' : 'Nothing on the shelf yet.' }}
                    </p>
                    @if (filled($query) && ! $basketFrozen)
                        <button type="button" wire:click="askToAddWithoutBarcode"
                                class="mt-3 text-sm font-semibold text-momo-800 hover:text-momo-900">
                            Add it
                        </button>
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    <div class="space-y-2">
        <div class="flex items-center justify-between gap-3">
            <p class="text-sm font-bold">Basket</p>
            @if ($checkout?->isOpen())
                <button type="button" wire:click="clearBasket"
                        class="text-xs font-semibold text-ink-500 hover:text-ink-800">
                    Clear
                </button>
            @elseif ($checkout?->isAwaitingConfirmation())
                <button type="button" wire:click="clearBasket"
                        class="text-xs font-semibold text-ink-500 hover:text-ink-800">
                    Take back
                </button>
            @endif
        </div>

        @forelse ($lines as $line)
            <div wire:key="line-{{ $line->id }}" class="rounded-xl border border-ink-200 px-4 py-3">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-ink-950">{{ $line->name }}</p>
                        <p class="mt-0.5 text-xs text-ink-500">
                            {{ $line->quantity }} × <x-money :amount="$line->unit_price" />
                        </p>
                    </div>
                    <x-money :amount="$line->lineTotal()" class="text-sm font-bold" />
                </div>
                @if ($checkout?->isOpen())
                    <div class="mt-3 flex gap-2">
                        <button type="button" wire:click="setLineQuantity({{ $line->id }}, {{ $line->quantity - 1 }})"
                                class="flex-1 rounded-lg border border-ink-300 px-3 py-2 text-xs font-semibold text-ink-700 transition hover:bg-ink-50 data-loading:pointer-events-none data-loading:opacity-50">
                            −
                        </button>
                        <button type="button" wire:click="addProduct({{ $line->product_id }})"
                                class="flex-1 rounded-lg border border-ink-300 px-3 py-2 text-xs font-semibold text-ink-700 transition hover:bg-ink-50 data-loading:pointer-events-none data-loading:opacity-50">
                            +
                        </button>
                        <button type="button" wire:click="removeLine({{ $line->id }})"
                                class="flex-1 rounded-lg border border-ink-300 px-3 py-2 text-xs font-semibold text-ink-700 transition hover:bg-ink-50 data-loading:pointer-events-none data-loading:opacity-50">
                            Remove
                        </button>
                    </div>
                @endif
            </div>
        @empty
            <p class="rounded-xl bg-ink-50 px-4 py-10 text-center text-sm text-ink-500">
                Scan or tap to add.
            </p>
        @endforelse

        @if ($lines->isNotEmpty())
            <div class="flex items-center justify-between rounded-xl bg-momo-50 px-4 py-3">
                <p class="text-sm font-semibold text-momo-800">Basket total</p>
                <x-money :amount="$basketTotal" class="text-base font-bold text-momo-900" />
            </div>
        @endif
    </div>

    @if ($checkout?->isOpen() && $lines->isNotEmpty() && ! $pendingPayment)
        <div class="space-y-2">
            <button type="button" wire:click="askToPayNow"
                    class="flex w-full items-center justify-between gap-3 rounded-xl bg-sunshine-400 px-4 py-3 text-left transition hover:bg-sunshine-500">
                <span>
                    <span class="block text-sm font-bold text-black">Pay Now</span>
                    <span class="block text-xs text-black/70">Collect the basket with MoMo. Nothing goes on the tab.</span>
                </span>
                <x-money :amount="$basketTotal" class="text-base font-bold text-black" />
            </button>

            @can('putOnTab', $checkout)
                <button type="button" wire:click="askToPutOnTab"
                        class="flex w-full items-center justify-between gap-3 rounded-xl border border-ink-300 bg-white px-4 py-3 text-left transition hover:bg-ink-50">
                    <span>
                        <span class="block text-sm font-bold text-ink-950">Put on TrustTab</span>
                        <span class="block text-xs text-ink-500">{{ $tab->customer->name }} confirms the basket. Then it goes on the tab.</span>
                    </span>
                    <x-money :amount="$basketTotal" class="text-base font-bold text-ink-950" />
                </button>
                <button type="button" wire:click="askToSplit"
                        class="flex w-full items-center justify-between gap-3 rounded-xl border border-ink-300 bg-white px-4 py-3 text-left transition hover:bg-ink-50">
                    <span>
                        <span class="block text-sm font-bold text-ink-950">Split</span>
                        <span class="block text-xs text-ink-500">Collect some with MoMo. The rest goes on the tab after they confirm.</span>
                    </span>
                    <x-money :amount="$basketTotal" class="text-base font-bold text-ink-950" />
                </button>
            @elseif (! $tab->hasAcceptedAgreement())
                <p class="text-xs text-ink-500">Agree a limit first before this can go on the tab.</p>
            @endcan
        </div>
    @endif

    @if ($confirmingPayNow && $checkout)
        @teleport('body')
            <div wire:click="cancelPayNow"
                 x-data
                 x-on:keydown.escape.window="$wire.cancelPayNow()"
                 class="fixed inset-0 z-50 flex items-end justify-center bg-momo-950/60 p-4 sm:items-center"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="pay-now-title">
                <div wire:click.stop class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg">
                    <p id="pay-now-title" class="text-base font-bold text-ink-950">Collect this basket?</p>
                    <p class="mt-1 text-sm text-ink-600">
                        MoMo will collect
                        <x-money :amount="$basketTotal" class="font-semibold" />
                        from
                        <span class="font-semibold tabular-nums">{{ Msisdn::forDisplay($tab->customer->msisdn) }}</span>.
                        Check your phone for a prompt. Stock comes off the shelf when MoMo confirms.
                    </p>
                    <div class="mt-5 flex gap-2">
                        <button type="button" wire:click="cancelPayNow" wire:loading.attr="disabled" wire:target="payNow"
                                class="flex-1 rounded-lg border border-ink-300 px-3 py-2.5 text-sm font-semibold text-ink-700 transition hover:bg-ink-50">
                            Not now
                        </button>
                        <button type="button" wire:click="payNow" wire:loading.attr="disabled" wire:target="payNow"
                                class="flex-1 rounded-lg bg-sunshine-400 px-3 py-2.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                            <span wire:loading.remove wire:target="payNow">Pay now</span>
                            <span wire:loading wire:target="payNow">Sending…</span>
                        </button>
                    </div>
                </div>
            </div>
        @endteleport
    @endif

    @if ($confirmingTrustTab && $checkout)
        @teleport('body')
            <div wire:click="cancelPutOnTab"
                 x-data
                 x-on:keydown.escape.window="$wire.cancelPutOnTab()"
                 class="fixed inset-0 z-50 flex items-end justify-center bg-momo-950/60 p-4 sm:items-center"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="trust-tab-title">
                <div wire:click.stop class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg">
                    <p id="trust-tab-title" class="text-base font-bold text-ink-950">Add this basket to the tab?</p>
                    <p class="mt-1 text-sm text-ink-600">
                        <x-money :amount="$basketTotal" class="font-semibold" />
                        will go on {{ $tab->customer->name }}'s tab after they confirm the lines.
                        Stock stays on the shelf until they accept.
                    </p>
                    @if ($tab->hasAcceptedAgreement())
                        <p class="mt-2 text-xs text-ink-500">
                            Available <x-money :amount="$tab->availableAmount()" class="font-semibold" />
                            of
                            <x-money :amount="$tab->limit_amount" class="font-semibold" />.
                        </p>
                    @endif
                    <div class="mt-5 flex gap-2">
                        <button type="button" wire:click="cancelPutOnTab" wire:loading.attr="disabled" wire:target="putOnTab"
                                class="flex-1 rounded-lg border border-ink-300 px-3 py-2.5 text-sm font-semibold text-ink-700 transition hover:bg-ink-50">
                            Not now
                        </button>
                        <button type="button" wire:click="putOnTab" wire:loading.attr="disabled" wire:target="putOnTab"
                                class="flex-1 rounded-lg bg-sunshine-400 px-3 py-2.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                            <span wire:loading.remove wire:target="putOnTab">Ask them to confirm</span>
                            <span wire:loading wire:target="putOnTab">Sending…</span>
                        </button>
                    </div>
                </div>
            </div>
        @endteleport
    @endif

    @if ($confirmingSplit && $checkout)
        @teleport('body')
            <div wire:click="cancelSplit"
                 x-data
                 x-on:keydown.escape.window="$wire.cancelSplit()"
                 class="fixed inset-0 z-50 flex items-end justify-center bg-momo-950/60 p-4 sm:items-center"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="split-title">
                <div wire:click.stop class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg">
                    <p id="split-title" class="text-base font-bold text-ink-950">Split this basket?</p>
                    <p class="mt-1 text-sm text-ink-600">
                        Collect some with MoMo from
                        <span class="font-semibold tabular-nums">{{ Msisdn::forDisplay($tab->customer->msisdn) }}</span>.
                        They confirm the rest first. Stock stays until both succeed.
                    </p>
                    <div class="mt-4">
                        <label for="momoAmount" class="block text-xs font-semibold text-ink-600">Collect with MoMo</label>
                        <input id="momoAmount" type="text" inputmode="decimal" wire:model.live="momoAmount"
                               class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm tabular-nums focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                        <p class="mt-1 text-xs text-ink-500">
                            Basket <x-money :amount="$basketTotal" class="font-semibold" />.
                            Rest on tab
                            <x-money :amount="$splitRemainder" class="font-semibold" />.
                        </p>
                        @error('momoAmount')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    @if ($tab->hasAcceptedAgreement())
                        <p class="mt-2 text-xs text-ink-500">
                            Available <x-money :amount="$tab->availableAmount()" class="font-semibold" />
                            of
                            <x-money :amount="$tab->limit_amount" class="font-semibold" />.
                        </p>
                    @endif
                    <div class="mt-5 flex gap-2">
                        <button type="button" wire:click="cancelSplit" wire:loading.attr="disabled" wire:target="split"
                                class="flex-1 rounded-lg border border-ink-300 px-3 py-2.5 text-sm font-semibold text-ink-700 transition hover:bg-ink-50">
                            Not now
                        </button>
                        <button type="button" wire:click="split" wire:loading.attr="disabled" wire:target="split"
                                class="flex-1 rounded-lg bg-sunshine-400 px-3 py-2.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                            <span wire:loading.remove wire:target="split">Ask them to confirm</span>
                            <span wire:loading wire:target="split">Sending…</span>
                        </button>
                    </div>
                </div>
            </div>
        @endteleport
    @endif

    @if ($addingProduct)
        @teleport('body')
            <div wire:click="cancelAddProduct"
                 x-data
                 x-on:keydown.escape.window="$wire.cancelAddProduct()"
                 class="fixed inset-0 z-50 flex items-end justify-center bg-momo-950/60 p-4 sm:items-center"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="quick-add-title">
                <div wire:click.stop class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg">
                    <p id="quick-add-title" class="text-base font-bold text-ink-950">Add to your book</p>
                    <p class="mt-1 text-sm text-ink-600">
                        Name it and put one on the basket. Stock stays on the shelf until they pay or confirm.
                    </p>
                    <div class="mt-4 space-y-3">
                        <div>
                            <label for="quickName" class="block text-xs font-semibold text-ink-600">Name</label>
                            <input id="quickName" type="text" wire:model="name" autocomplete="off"
                                   class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                            @error('name')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="quickPrice" class="block text-xs font-semibold text-ink-600">Price</label>
                            <input id="quickPrice" type="text" inputmode="decimal" wire:model="sellingPrice"
                                   class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm tabular-nums focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                            @error('sellingPrice')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="quickStock" class="block text-xs font-semibold text-ink-600">On the shelf</label>
                            <input id="quickStock" type="number" min="0" wire:model="stockQuantity"
                                   class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm tabular-nums focus:border-momo-800 focus:ring-1 focus:ring-momo-800 focus:outline-none">
                            <p class="mt-1 text-xs text-ink-500">Leave at 1 if you are selling this now.</p>
                            @error('stockQuantity')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        @if (filled($barcode))
                            <p class="text-xs text-ink-500">Barcode <span class="font-semibold tabular-nums">{{ $barcode }}</span></p>
                        @endif
                        @error('barcode')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="mt-5 flex gap-2">
                        <button type="button" wire:click="cancelAddProduct" wire:loading.attr="disabled" wire:target="addNewProduct"
                                class="flex-1 rounded-lg border border-ink-300 px-3 py-2.5 text-sm font-semibold text-ink-700 transition hover:bg-ink-50">
                            Not now
                        </button>
                        <button type="button" wire:click="addNewProduct" wire:loading.attr="disabled" wire:target="addNewProduct"
                                class="flex-1 rounded-lg bg-sunshine-400 px-3 py-2.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                            <span class="in-data-loading:hidden">Add and sell</span>
                            <span class="not-in-data-loading:hidden">Adding…</span>
                        </button>
                    </div>
                </div>
            </div>
        @endteleport
    @endif

    <x-barcode-scan-sheet />
</div>
