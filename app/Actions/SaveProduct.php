<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProduct
{
    /**
     * Create or update a catalogue item for this merchant. Stock is the count
     * on the shelf; it is not decremented here. Checkout will lock and take
     * stock only when a sale is complete.
     *
     * @param  array{
     *     name: string,
     *     barcode?: string|null,
     *     selling_price: string,
     *     stock_quantity: int,
     *     low_stock_threshold: int,
     *     is_active?: bool
     * }  $attributes
     */
    public function handle(User $merchant, array $attributes, ?Product $product = null): Product
    {
        $barcode = $this->normalizedBarcode($attributes['barcode'] ?? null);

        $this->assertUnique($merchant, $attributes['name'], $barcode, $product);

        return DB::transaction(function () use ($merchant, $attributes, $product, $barcode): Product {
            $product ??= new Product;
            $product->merchant_id = $merchant->id;
            $product->name = $attributes['name'];
            $product->barcode = $barcode;
            $product->selling_price = $attributes['selling_price'];
            $product->stock_quantity = $attributes['stock_quantity'];
            $product->low_stock_threshold = $attributes['low_stock_threshold'];
            $product->is_active = $attributes['is_active'] ?? true;
            $product->save();

            return $product;
        });
    }

    protected function normalizedBarcode(?string $barcode): ?string
    {
        $barcode = is_string($barcode) ? trim($barcode) : '';

        return $barcode === '' ? null : $barcode;
    }

    protected function assertUnique(User $merchant, string $name, ?string $barcode, ?Product $product): void
    {
        $nameTaken = Product::query()
            ->where('merchant_id', $merchant->id)
            ->where('name', $name)
            ->when($product, fn ($query) => $query->whereKeyNot($product->getKey()))
            ->exists();

        if ($nameTaken) {
            throw ValidationException::withMessages([
                'name' => 'You already have a product with this name.',
            ]);
        }

        if ($barcode === null) {
            return;
        }

        $barcodeTaken = Product::query()
            ->where('merchant_id', $merchant->id)
            ->where('barcode', $barcode)
            ->when($product, fn ($query) => $query->whereKeyNot($product->getKey()))
            ->exists();

        if ($barcodeTaken) {
            throw ValidationException::withMessages([
                'barcode' => 'That barcode is already on another product.',
            ]);
        }
    }
}
