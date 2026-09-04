<?php

use App\Actions\SaveProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->merchant = User::factory()->merchant('Mama Nandi Spaza')->create();
    $this->customer = User::factory()->create();
});

it('lets the merchant add a product to their catalogue', function () {
    $product = app(SaveProduct::class)->handle($this->merchant, [
        'name' => 'Bread',
        'barcode' => '6001234000018',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
        'low_stock_threshold' => 8,
    ]);

    expect($product->merchant_id)->toBe($this->merchant->id)
        ->and($product->name)->toBe('Bread')
        ->and($product->selling_price)->toBe('18.00')
        ->and($product->stock_quantity)->toBe(40)
        ->and($product->is_active)->toBeTrue()
        ->and($product->isLowStock())->toBeFalse();
});

it('treats stock at or below the threshold as low without taking any off the shelf', function () {
    $product = Product::factory()->forMerchant($this->merchant)->create([
        'stock_quantity' => 5,
        'low_stock_threshold' => 8,
    ]);

    expect($product->isLowStock())->toBeTrue()
        ->and($product->stock_quantity)->toBe(5);
});

it('does not let two products in one shop share a name or barcode', function () {
    app(SaveProduct::class)->handle($this->merchant, [
        'name' => 'Bread',
        'barcode' => '6001234000018',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
        'low_stock_threshold' => 8,
    ]);

    expect(fn () => app(SaveProduct::class)->handle($this->merchant, [
        'name' => 'Bread',
        'barcode' => '6001234000999',
        'selling_price' => '20.00',
        'stock_quantity' => 10,
        'low_stock_threshold' => 4,
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(SaveProduct::class)->handle($this->merchant, [
        'name' => 'Loaf',
        'barcode' => '6001234000018',
        'selling_price' => '20.00',
        'stock_quantity' => 10,
        'low_stock_threshold' => 4,
    ]))->toThrow(ValidationException::class);
});

it('lets several products skip a barcode', function () {
    app(SaveProduct::class)->handle($this->merchant, [
        'name' => 'Airtime',
        'barcode' => '',
        'selling_price' => '50.00',
        'stock_quantity' => 99,
        'low_stock_threshold' => 10,
    ]);

    $second = app(SaveProduct::class)->handle($this->merchant, [
        'name' => 'Paraffin',
        'barcode' => null,
        'selling_price' => '40.00',
        'stock_quantity' => 12,
        'low_stock_threshold' => 4,
    ]);

    expect($second->barcode)->toBeNull()
        ->and(Product::query()->where('merchant_id', $this->merchant->id)->whereNull('barcode')->count())->toBe(2);
});

it('lets another shop reuse the same barcode', function () {
    app(SaveProduct::class)->handle($this->merchant, [
        'name' => 'Bread',
        'barcode' => '6001234000018',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
        'low_stock_threshold' => 8,
    ]);

    $other = User::factory()->merchant()->create();

    $product = app(SaveProduct::class)->handle($other, [
        'name' => 'Bread',
        'barcode' => '6001234000018',
        'selling_price' => '19.00',
        'stock_quantity' => 12,
        'low_stock_threshold' => 4,
    ]);

    expect($product->merchant_id)->toBe($other->id)
        ->and(Product::query()->where('barcode', '6001234000018')->count())->toBe(2);
});

it('lets the merchant add and edit products on the catalogue page', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::products')
        ->set('name', 'Bread')
        ->set('sellingPrice', '18.00')
        ->set('barcode', '6001234000018')
        ->set('stockQuantity', '40')
        ->set('lowStockThreshold', '8')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Bread')
        ->assertSee('40 in stock');

    $product = Product::query()->sole();

    Livewire::actingAs($this->merchant)
        ->test('pages::products')
        ->call('edit', $product->id)
        ->set('sellingPrice', '20.00')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->selling_price)->toBe('20.00')
        ->and($product->fresh()->stock_quantity)->toBe(40);
});

it('filters the catalogue by name or barcode', function () {
    Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'barcode' => '6001234000018',
    ]);
    Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Milk',
        'barcode' => '6001234000025',
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::products')
        ->assertSee('Bread')
        ->assertSee('Milk')
        ->set('query', 'Mil')
        ->assertSee('Milk')
        ->assertDontSee('Bread')
        ->set('query', '400001')
        ->assertSee('Bread')
        ->assertDontSee('Milk');
});

it('lets the merchant take a product off the shelf without deleting it', function () {
    $product = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Milk']);

    Livewire::actingAs($this->merchant)
        ->test('pages::products')
        ->call('toggleActive', $product->id)
        ->assertSee('off the shelf');

    expect($product->fresh()->is_active)->toBeFalse();
});

it('hides the catalogue from a customer', function () {
    $this->actingAs($this->customer)
        ->get(route('products'))
        ->assertForbidden();

    expect($this->customer->can('viewAny', Product::class))->toBeFalse()
        ->and($this->merchant->can('create', Product::class))->toBeTrue();
});

it('does not let a merchant edit another shops product', function () {
    $stranger = User::factory()->merchant()->create();
    $product = Product::factory()->forMerchant($stranger)->create(['name' => 'Sugar']);

    expect($this->merchant->can('update', $product))->toBeFalse();

    Livewire::actingAs($this->merchant)
        ->test('pages::products')
        ->assertDontSee('Sugar')
        ->call('edit', $product->id)
        ->assertNotFound();
});

it('sends guests to login', function () {
    $this->get(route('products'))->assertRedirect(route('login'));
});
