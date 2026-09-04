<?php

use App\Enums\CheckoutStatus;
use App\Models\Product;
use App\Models\Tab;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->merchant = User::factory()->merchant('Mama Nandi Spaza')->create();
    $this->customer = User::factory()->create(['name' => 'Sipho Khumalo']);
    $this->tab = Tab::factory()->between($this->merchant, $this->customer)->create();
});

it('lets the merchant add a product to the till by tapping it', function () {
    $bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'barcode' => '6001234000018',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $bread->id)
        ->assertHasNoErrors()
        ->assertSee('Bread');

    $checkout = $this->tab->openCheckout();

    expect($checkout)->not->toBeNull()
        ->and($checkout->status)->toBe(CheckoutStatus::Open)
        ->and($checkout->total())->toBe('18.00')
        ->and($checkout->lines->sole()->quantity)->toBe(1)
        ->and($checkout->lines->sole()->unit_price)->toBe('18.00')
        ->and($bread->fresh()->stock_quantity)->toBe(40);
});

it('adds a product from a barcode without taking stock', function () {
    $milk = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Milk',
        'barcode' => '6001234000025',
        'selling_price' => '22.00',
        'stock_quantity' => 24,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addByBarcode', '6001234000025')
        ->assertHasNoErrors()
        ->assertSee('Milk');

    expect($this->tab->openCheckout()->total())->toBe('22.00')
        ->and($milk->fresh()->stock_quantity)->toBe(24);
});

it('increments the same line when the barcode is scanned again', function () {
    $bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'barcode' => '6001234000018',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addByBarcode', '6001234000018')
        ->call('addByBarcode', '6001234000018')
        ->assertHasNoErrors();

    $checkout = $this->tab->openCheckout();

    expect($checkout->lines)->toHaveCount(1)
        ->and($checkout->lines->sole()->quantity)->toBe(2)
        ->and($checkout->total())->toBe('36.00')
        ->and($bread->fresh()->stock_quantity)->toBe(40);
});

it('keeps the first price when the same product is added again', function () {
    $bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $bread->id);

    $bread->selling_price = '20.00';
    $bread->save();

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $bread->id);

    $line = $this->tab->openCheckout()->lines->sole();

    expect($line->quantity)->toBe(2)
        ->and($line->unit_price)->toBe('18.00')
        ->and($this->tab->openCheckout()->total())->toBe('36.00');
});

it('totals bread milk and eggs at 75.00 and leaves stock on the shelf', function () {
    $bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'barcode' => '6001234000018',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
    ]);
    $milk = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Milk',
        'barcode' => '6001234000025',
        'selling_price' => '22.00',
        'stock_quantity' => 24,
    ]);
    $eggs = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Eggs',
        'barcode' => '6001234000032',
        'selling_price' => '35.00',
        'stock_quantity' => 18,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $bread->id)
        ->call('addProduct', $milk->id)
        ->call('addProduct', $eggs->id)
        ->assertHasNoErrors();

    $checkout = $this->tab->openCheckout();

    expect($checkout->total())->toBe('75.00')
        ->and($this->tab->checkouts()->open()->count())->toBe(1)
        ->and($bread->fresh()->stock_quantity)->toBe(40)
        ->and($milk->fresh()->stock_quantity)->toBe(24)
        ->and($eggs->fresh()->stock_quantity)->toBe(18);
});

it('reuses the open checkout instead of opening a second basket', function () {
    $bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
    ]);
    $milk = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Milk',
        'selling_price' => '22.00',
        'stock_quantity' => 24,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $bread->id);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $milk->id);

    expect($this->tab->checkouts()->count())->toBe(1)
        ->and($this->tab->openCheckout()->total())->toBe('40.00');
});

it('refuses an inactive product without leaking it onto the till', function () {
    $eggs = Product::factory()->inactive()->forMerchant($this->merchant)->create([
        'name' => 'Eggs',
        'barcode' => '6001234000032',
        'selling_price' => '35.00',
        'stock_quantity' => 18,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->assertDontSee('Eggs')
        ->call('addByBarcode', '6001234000032')
        ->assertHasErrors(['basket'])
        ->assertSee('That is off the shelf.');

    expect($this->tab->checkouts()->count())->toBe(0)
        ->and($eggs->fresh()->stock_quantity)->toBe(18);
});

it('keeps an unknown barcode off the basket', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addByBarcode', '6001234099999')
        ->assertSet('scanStatus', 'missing')
        ->assertSee('Not in your book yet.')
        ->assertSee('Add it');

    expect($this->tab->checkouts()->count())->toBe(0);
});

it('filters the shelf by name without showing another shops goods', function () {
    Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'barcode' => '6001234000018',
        'selling_price' => '18.00',
    ]);
    Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Milk',
        'barcode' => '6001234000025',
        'selling_price' => '22.00',
    ]);
    Product::factory()->forMerchant(User::factory()->merchant()->create())->create([
        'name' => 'Sugar',
        'barcode' => '6001234000025',
        'selling_price' => '28.00',
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->assertSee('Bread')
        ->assertSee('Milk')
        ->assertDontSee('Sugar')
        ->set('query', 'Mil')
        ->assertSee('Milk')
        ->assertDontSee('Bread')
        ->assertDontSee('Sugar')
        ->set('query', '400002')
        ->assertSee('Milk')
        ->assertDontSee('Bread');
});

it('adds a missing barcode as a new product and puts it on the basket', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addByBarcode', '6001234099999')
        ->call('askToAddProduct')
        ->set('name', 'Paraffin')
        ->set('sellingPrice', '40.00')
        ->set('stockQuantity', '12')
        ->call('addNewProduct')
        ->assertHasNoErrors()
        ->assertSee('Paraffin');

    $product = Product::query()->where('merchant_id', $this->merchant->id)->sole();

    expect($product->name)->toBe('Paraffin')
        ->and($product->barcode)->toBe('6001234099999')
        ->and($product->selling_price)->toBe('40.00')
        ->and($product->stock_quantity)->toBe(12)
        ->and($this->tab->openCheckout()->total())->toBe('40.00')
        ->and($this->tab->openCheckout()->lines->sole()->quantity)->toBe(1);
});

it('adds a product without a barcode from the till', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->set('query', 'Airtime')
        ->call('askToAddWithoutBarcode')
        ->assertSet('name', 'Airtime')
        ->set('sellingPrice', '50.00')
        ->set('stockQuantity', '')
        ->call('addNewProduct')
        ->assertHasNoErrors()
        ->assertSee('Airtime');

    $product = Product::query()->where('merchant_id', $this->merchant->id)->sole();

    expect($product->barcode)->toBeNull()
        ->and($product->stock_quantity)->toBe(1)
        ->and($product->selling_price)->toBe('50.00')
        ->and($this->tab->openCheckout()->total())->toBe('50.00');
});

it('asks for a name before adding a product from the till', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('askToAddProduct')
        ->set('sellingPrice', '10.00')
        ->call('addNewProduct')
        ->assertHasErrors(['name']);

    expect(Product::query()->count())->toBe(0)
        ->and($this->tab->checkouts()->count())->toBe(0);
});

it('refuses a name already in the book from the till', function () {
    Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('askToAddWithoutBarcode')
        ->set('name', 'Bread')
        ->set('sellingPrice', '19.00')
        ->call('addNewProduct')
        ->assertHasErrors(['name'])
        ->assertSee('You already have a product with this name.');

    expect(Product::query()->where('merchant_id', $this->merchant->id)->count())->toBe(1)
        ->and($this->tab->checkouts()->count())->toBe(0);
});

it('escapes a product name so it cannot become markup on the till', function () {
    $name = 'Bread <script>alert(1)</script>';

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('askToAddWithoutBarcode')
        ->set('name', $name)
        ->set('sellingPrice', '18.00')
        ->call('addNewProduct')
        ->assertHasNoErrors()
        ->assertDontSee($name, false)
        ->assertSee('Bread', false);
});

it('does not reveal another shops product with the same barcode', function () {
    $stranger = User::factory()->merchant()->create();
    $theirs = Product::factory()->forMerchant($stranger)->create([
        'name' => 'Sugar',
        'barcode' => '6001234000018',
        'selling_price' => '28.00',
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addByBarcode', '6001234000018')
        ->assertDontSee('Sugar')
        ->assertSet('scanStatus', 'missing')
        ->call('addProduct', $theirs->id)
        ->assertNotFound();

    expect($this->tab->checkouts()->count())->toBe(0);
});

it('stops a line going past what is on the shelf', function () {
    $bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'selling_price' => '18.00',
        'stock_quantity' => 1,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $bread->id)
        ->call('addProduct', $bread->id)
        ->assertHasErrors(['basket'])
        ->assertSee('Only 1 on the shelf.');

    expect($this->tab->openCheckout()->lines->sole()->quantity)->toBe(1)
        ->and($bread->fresh()->stock_quantity)->toBe(1);
});

it('removes a line when the quantity is set to zero', function () {
    $bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
    ]);

    $component = Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $bread->id);

    $line = $this->tab->openCheckout()->lines->sole();

    $component->call('setLineQuantity', $line->id, 0)->assertHasNoErrors();

    expect($this->tab->openCheckout()->lines)->toHaveCount(0)
        ->and($bread->fresh()->stock_quantity)->toBe(40);
});

it('cancels the open checkout when the merchant clears the basket', function () {
    $bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $bread->id)
        ->call('clearBasket')
        ->assertDontSee('Basket total')
        ->assertSee('Scan or tap to add.');

    $checkout = $this->tab->checkouts()->sole();

    expect($this->tab->openCheckout())->toBeNull()
        ->and($checkout->status)->toBe(CheckoutStatus::Cancelled)
        ->and($checkout->cancelled_at)->not->toBeNull()
        ->and($bread->fresh()->stock_quantity)->toBe(40);
});

it('lets the merchant use the till before the customer agrees the tab', function () {
    $this->tab->agreement_accepted_at = null;
    $this->tab->save();

    $bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $bread->id)
        ->assertHasNoErrors()
        ->assertSee('Pay Now')
        ->assertDontSee('Put on TrustTab');

    expect($this->tab->openCheckout()->total())->toBe('18.00');
});

it('hides the till from the customer', function () {
    $this->actingAs($this->customer)
        ->get(route('tabs.till', $this->tab))
        ->assertForbidden();

    $this->actingAs($this->customer)
        ->get(route('tabs.show', $this->tab))
        ->assertOk()
        ->assertDontSee('Till');
});

it('sends guests to login', function () {
    $this->get(route('tabs.till', $this->tab))->assertRedirect(route('login'));
});

it('asks for a barcode before looking it up', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('lookUpBarcode')
        ->assertHasErrors(['barcode']);
});
