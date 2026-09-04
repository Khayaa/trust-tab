<?php

use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->merchant = User::factory()->merchant('Mama Nandi Spaza')->create();
    $this->customer = User::factory()->create();
});

it('opens a product when the merchant looks up its barcode', function () {
    $product = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'barcode' => '6001234000018',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
        'low_stock_threshold' => 8,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::products')
        ->set('barcode', '6001234000018')
        ->call('findByBarcode')
        ->assertHasNoErrors()
        ->assertSet('editingId', $product->id)
        ->assertSet('name', 'Bread')
        ->assertSet('sellingPrice', '18.00')
        ->assertSet('scanStatus', 'found')
        ->assertSee('This is already in your book.')
        ->assertSee('Edit product');

    expect($product->fresh()->stock_quantity)->toBe(40);
});

it('fills the barcode from a scan without taking stock', function () {
    $product = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Milk',
        'barcode' => '6001234000025',
        'stock_quantity' => 24,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::products')
        ->call('findByBarcode', '6001234000025')
        ->assertSet('barcode', '6001234000025')
        ->assertSet('editingId', $product->id)
        ->assertSet('scanStatus', 'found');

    expect($product->fresh()->stock_quantity)->toBe(24);
});

it('keeps an unknown barcode so the merchant can add it', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::products')
        ->set('barcode', '6001234099999')
        ->call('findByBarcode')
        ->assertSet('editingId', null)
        ->assertSet('scanStatus', 'missing')
        ->assertSet('barcode', '6001234099999')
        ->assertSee('Not in your book yet.');
});

it('does not reveal another shops product with the same barcode', function () {
    $stranger = User::factory()->merchant()->create();
    Product::factory()->forMerchant($stranger)->create([
        'name' => 'Sugar',
        'barcode' => '6001234000018',
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::products')
        ->call('findByBarcode', '6001234000018')
        ->assertDontSee('Sugar')
        ->assertSet('editingId', null)
        ->assertSet('scanStatus', 'missing');
});

it('still finds a product that is off the shelf', function () {
    $product = Product::factory()->inactive()->forMerchant($this->merchant)->create([
        'name' => 'Eggs',
        'barcode' => '6001234000032',
        'stock_quantity' => 18,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::products')
        ->call('findByBarcode', '6001234000032')
        ->assertSet('editingId', $product->id)
        ->assertSee('off the shelf');

    expect($product->fresh()->stock_quantity)->toBe(18);
});

it('asks for a barcode before looking up', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::products')
        ->call('findByBarcode')
        ->assertHasErrors(['barcode']);
});
