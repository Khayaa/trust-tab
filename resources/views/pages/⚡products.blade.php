<?php

use App\Actions\SaveProduct;
use App\Models\Product;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Products')]
class extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $barcode = '';

    public string $sellingPrice = '';

    public string $stockQuantity = '0';

    public string $lowStockThreshold = '8';

    public bool $isActive = true;

    public ?string $scanStatus = null;

    public string $query = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Product::class);
    }

    public function findByBarcode(?string $code = null): void
    {
        $this->authorize('viewAny', Product::class);

        if (is_string($code) && trim($code) !== '') {
            $this->barcode = trim($code);
        }

        $validated = $this->validate([
            'barcode' => 'required|string|max:64',
        ]);

        $barcode = trim($validated['barcode']);
        $this->barcode = $barcode;
        $this->scanStatus = null;

        $product = Product::query()
            ->where('merchant_id', auth()->id())
            ->where('barcode', $barcode)
            ->first();

        if ($product === null) {
            $this->scanStatus = 'missing';

            return;
        }

        $this->authorize('view', $product);
        $this->edit($product->id);
        $this->scanStatus = 'found';
    }

    public function save(SaveProduct $action): void
    {
        $product = $this->editingProduct();

        $product === null
            ? $this->authorize('create', Product::class)
            : $this->authorize('update', $product);

        $validated = $this->validate([
            'name' => 'required|string|max:120',
            'barcode' => 'nullable|string|max:64',
            'sellingPrice' => 'required|numeric|min:0.01|max:9999999999.99|decimal:0,2',
            'stockQuantity' => 'required|integer|min:0|max:999999',
            'lowStockThreshold' => 'required|integer|min:0|max:999999',
            'isActive' => 'boolean',
        ]);

        $action->handle(auth()->user(), [
            'name' => $validated['name'],
            'barcode' => $validated['barcode'] ?: null,
            'selling_price' => $validated['sellingPrice'],
            'stock_quantity' => (int) $validated['stockQuantity'],
            'low_stock_threshold' => (int) $validated['lowStockThreshold'],
            'is_active' => $validated['isActive'],
        ], $product);

        $this->resetForm();
    }

    public function edit(int $productId): void
    {
        $product = $this->ownedProduct($productId);

        $this->authorize('update', $product);

        $this->editingId = $product->id;
        $this->name = $product->name;
        $this->barcode = $product->barcode ?? '';
        $this->sellingPrice = $product->selling_price;
        $this->stockQuantity = (string) $product->stock_quantity;
        $this->lowStockThreshold = (string) $product->low_stock_threshold;
        $this->isActive = $product->is_active;
        $this->scanStatus = null;
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function toggleActive(int $productId): void
    {
        $product = $this->ownedProduct($productId);

        $this->authorize('update', $product);

        $product->is_active = ! $product->is_active;
        $product->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'products' => Product::query()
                ->where('merchant_id', auth()->id())
                ->search($this->query)
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
            'editing' => $this->editingId !== null,
        ];
    }

    protected function editingProduct(): ?Product
    {
        if ($this->editingId === null) {
            return null;
        }

        return $this->ownedProduct($this->editingId);
    }

    protected function ownedProduct(int $productId): Product
    {
        return Product::query()
            ->where('merchant_id', auth()->id())
            ->whereKey($productId)
            ->first() ?? abort(404);
    }

    protected function resetForm(): void
    {
        $this->reset('editingId', 'name', 'barcode', 'sellingPrice', 'isActive', 'scanStatus');
        $this->stockQuantity = '0';
        $this->lowStockThreshold = '8';
        $this->isActive = true;
        $this->resetValidation();
    }
};
?>

<div class="space-y-5">
    <div>
        <h1 class="text-xl font-bold tracking-tight text-ink-950">Products</h1>
        <p class="mt-1 text-sm text-ink-500">What you sell. Stock is counted here; checkout will take it off the shelf later.</p>
    </div>

    <form wire:submit="save" class="space-y-4 rounded-2xl border border-ink-200 bg-white p-5 shadow-sm">
        <div class="space-y-1">
            <h2 class="text-sm font-bold text-ink-950">{{ $editing ? 'Edit product' : 'Add a product' }}</h2>
            <p class="text-xs text-ink-500">Scan or type a barcode to find it. Stock does not move yet.</p>
        </div>

        <div class="space-y-1.5">
            <label for="name" class="block text-xs font-semibold text-ink-700">Name</label>
            <input wire:model="name" id="name" type="text" required
                   class="block w-full rounded-xl border border-ink-200 px-4 py-3.5 text-sm outline-none transition focus:border-momo-800 focus:ring-1 focus:ring-momo-800">
            @error('name')
                <p class="pt-0.5 text-xs font-medium text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="space-y-1.5">
            <label for="sellingPrice" class="block text-xs font-semibold text-ink-700">Price</label>
            <input wire:model="sellingPrice" id="sellingPrice" type="text" inputmode="decimal" required
                   class="block w-full rounded-xl border border-ink-200 px-4 py-3.5 text-sm tabular-nums outline-none transition focus:border-momo-800 focus:ring-1 focus:ring-momo-800">
            @error('sellingPrice')
                <p class="pt-0.5 text-xs font-medium text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="space-y-1.5">
            <label for="barcode" class="block text-xs font-semibold text-ink-700">Barcode</label>
            <input wire:model="barcode" id="barcode" type="text" inputmode="numeric" autocomplete="off"
                   wire:keydown.enter.stop.prevent="findByBarcode"
                   class="block w-full rounded-xl border border-ink-200 px-4 py-3.5 text-sm tabular-nums outline-none transition focus:border-momo-800 focus:ring-1 focus:ring-momo-800">
            <div class="mt-2 flex gap-2">
                <button type="button" data-barcode-scan data-barcode-action="findByBarcode"
                        class="flex-1 rounded-xl border border-ink-300 px-4 py-3 text-sm font-semibold text-ink-700 transition hover:bg-ink-50">
                    Scan
                </button>
                <button type="button" wire:click="findByBarcode"
                        class="flex-1 rounded-xl border border-ink-300 px-4 py-3 text-sm font-semibold text-ink-700 transition hover:bg-ink-50 data-loading:pointer-events-none data-loading:opacity-50">
                    <span class="in-data-loading:hidden">Look up</span>
                    <span class="not-in-data-loading:hidden">Looking…</span>
                </button>
            </div>
            @error('barcode')
                <p class="pt-0.5 text-xs font-medium text-red-600">{{ $message }}</p>
            @enderror
            @if ($scanStatus === 'found')
                <p class="pt-0.5 text-xs font-medium text-momo-800">This is already in your book.</p>
            @elseif ($scanStatus === 'missing')
                <p class="pt-0.5 text-xs font-medium text-ink-600">Not in your book yet. Fill in the rest to add it.</p>
            @endif
            <p data-barcode-error hidden class="pt-0.5 text-xs font-medium text-red-600"></p>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div class="space-y-1.5">
                <label for="stockQuantity" class="block text-xs font-semibold text-ink-700">Stock</label>
                <input wire:model="stockQuantity" id="stockQuantity" type="number" min="0" required
                       class="block w-full rounded-xl border border-ink-200 px-4 py-3.5 text-sm tabular-nums outline-none transition focus:border-momo-800 focus:ring-1 focus:ring-momo-800">
                @error('stockQuantity')
                    <p class="pt-0.5 text-xs font-medium text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="space-y-1.5">
                <label for="lowStockThreshold" class="block text-xs font-semibold text-ink-700">Low at</label>
                <input wire:model="lowStockThreshold" id="lowStockThreshold" type="number" min="0" required
                       class="block w-full rounded-xl border border-ink-200 px-4 py-3.5 text-sm tabular-nums outline-none transition focus:border-momo-800 focus:ring-1 focus:ring-momo-800">
                @error('lowStockThreshold')
                    <p class="pt-0.5 text-xs font-medium text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <label class="flex items-center gap-2.5 text-xs text-ink-600">
            <input wire:model="isActive" type="checkbox" class="size-4 rounded border-ink-300 text-momo-800 focus:ring-momo-800">
            On the shelf
        </label>

        <div class="flex gap-2">
            <button type="submit"
                    class="flex-1 rounded-xl bg-sunshine-400 px-4 py-3.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                <span class="in-data-loading:hidden">{{ $editing ? 'Save changes' : 'Add product' }}</span>
                <span class="not-in-data-loading:hidden">Saving…</span>
            </button>
            @if ($editing)
                <button type="button" wire:click="cancelEdit"
                        class="rounded-xl border border-ink-300 px-4 py-3.5 text-sm font-semibold text-ink-700 transition hover:bg-ink-50">
                    Cancel
                </button>
            @endif
        </div>
    </form>

    <div>
        <label for="productSearch" class="sr-only">Search products</label>
        <input id="productSearch" type="search" autocomplete="off" wire:model.live.debounce.250ms="query"
               placeholder="Search by name or barcode"
               class="w-full rounded-xl border border-ink-200 px-4 py-3 text-sm outline-none transition focus:border-momo-800 focus:ring-1 focus:ring-momo-800">
    </div>

    <ul class="space-y-2">
        @foreach ($products as $product)
            <li wire:key="product-{{ $product->id }}"
                class="rounded-xl border border-ink-200 bg-white px-4 py-3 {{ $product->is_active ? '' : 'opacity-60' }}">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-ink-950">{{ $product->name }}</p>
                        <p class="mt-0.5 text-xs text-ink-500">
                            {{ $product->barcode ?: 'No barcode' }}
                            &middot;
                            {{ $product->stock_quantity }} in stock
                            @if ($product->isLowStock())
                                <span class="font-semibold text-red-700">· low</span>
                            @endif
                            @unless ($product->is_active)
                                <span class="font-semibold text-ink-500">· off the shelf</span>
                            @endunless
                        </p>
                    </div>
                    <x-money :amount="$product->selling_price" class="text-sm font-bold text-momo-800" />
                </div>
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="edit({{ $product->id }})"
                            class="flex-1 rounded-lg border border-ink-300 px-3 py-2 text-xs font-semibold text-ink-700 transition hover:bg-ink-50">
                        Edit
                    </button>
                    <button type="button" wire:click="toggleActive({{ $product->id }})"
                            class="flex-1 rounded-lg border border-ink-300 px-3 py-2 text-xs font-semibold text-ink-700 transition hover:bg-ink-50">
                        {{ $product->is_active ? 'Take off shelf' : 'Put on shelf' }}
                    </button>
                </div>
            </li>
        @endforeach
    </ul>

    @if ($products->isEmpty())
        <p class="rounded-xl bg-ink-50 px-4 py-10 text-center text-sm text-ink-500">
            {{ filled($query) ? 'Nothing matches that name.' : 'No products yet.' }}
        </p>
    @endif

    <x-barcode-scan-sheet />
</div>
