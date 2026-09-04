<?php

use App\Enums\CheckoutStatus;
use App\Enums\TabEntryStatus;
use App\Models\Product;
use App\Models\Tab;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->merchant = User::factory()->merchant('Mama Nandi Spaza')->create();
    $this->customer = User::factory()->create(['name' => 'Sipho Khumalo']);
    $this->stranger = User::factory()->create();
    $this->tab = Tab::factory()->between($this->merchant, $this->customer)->create();

    $this->bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'barcode' => '6001234000018',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
    ]);

    $this->milk = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Milk',
        'selling_price' => '22.00',
        'stock_quantity' => 24,
    ]);
});

it('holds the basket on the tab until the customer confirms it', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('addProduct', $this->milk->id)
        ->call('askToPutOnTab')
        ->assertSet('confirmingTrustTab', true)
        ->assertSee('Add this basket to the tab?')
        ->call('putOnTab')
        ->assertHasNoErrors()
        ->assertSee('Waiting for Sipho Khumalo to confirm')
        ->assertDontSee('Put on TrustTab')
        ->assertDontSee('Pay Now');

    $checkout = $this->tab->currentTillCheckout();

    expect($checkout->status)->toBe(CheckoutStatus::AwaitingConfirmation)
        ->and($checkout->momo_amount)->toBe('0.00')
        ->and($checkout->tab_amount)->toBe('40.00')
        ->and($this->bread->fresh()->stock_quantity)->toBe(40)
        ->and($this->tab->outstandingBalance())->toBe('0.00')
        ->and($this->tab->entries()->count())->toBe(0)
        ->and($this->tab->hasEntryAwaiting())->toBeTrue();
});

it('writes confirmed lines and takes stock when the customer accepts the basket', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('addProduct', $this->bread->id)
        ->call('addProduct', $this->milk->id)
        ->call('putOnTab');

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->assertSee('This will be added to your TrustTab')
        ->assertSee('Bread')
        ->assertSee('Milk')
        ->call('confirmCheckout')
        ->assertHasNoErrors()
        ->assertDontSee('This will be added to your TrustTab')
        ->assertSee('2× Bread')
        ->assertSee('Milk');

    $checkout = $this->tab->checkouts()->sole();

    expect($checkout->status)->toBe(CheckoutStatus::Completed)
        ->and($checkout->completed_at)->not->toBeNull()
        ->and($this->bread->fresh()->stock_quantity)->toBe(38)
        ->and($this->milk->fresh()->stock_quantity)->toBe(23)
        ->and($this->tab->outstandingBalance())->toBe('58.00')
        ->and($this->tab->entries()->count())->toBe(2);

    $breadEntry = $this->tab->entries()->where('description', '2× Bread')->sole();

    expect($breadEntry->status)->toBe(TabEntryStatus::Confirmed)
        ->and($breadEntry->amount)->toBe('36.00')
        ->and($breadEntry->checkout_id)->toBe($checkout->id)
        ->and($breadEntry->confirmed_at)->not->toBeNull();
});

it('reopens the basket and takes no stock when the customer rejects it', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('putOnTab');

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('disputeCheckout')
        ->assertHasNoErrors()
        ->assertDontSee('This will be added to your TrustTab');

    expect($this->tab->openCheckout()->status)->toBe(CheckoutStatus::Open)
        ->and($this->tab->openCheckout()->total())->toBe('18.00')
        ->and($this->bread->fresh()->stock_quantity)->toBe(40)
        ->and($this->tab->entries()->count())->toBe(0)
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('does not add the basket twice when the merchant sends it again', function () {
    $component = Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('putOnTab')
        ->call('putOnTab');

    expect($this->tab->checkouts()->count())->toBe(1)
        ->and($this->tab->currentTillCheckout()->status)->toBe(CheckoutStatus::AwaitingConfirmation);

    $component->call('addProduct', $this->milk->id)
        ->assertSee('Waiting for them to confirm this basket');

    expect($this->tab->currentTillCheckout()->lines)->toHaveCount(1);
});

it('is safe to confirm the same basket twice', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('putOnTab');

    $component = Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab]);

    $component->call('confirmCheckout');
    $component->call('confirmCheckout');

    expect($this->tab->entries()->count())->toBe(1)
        ->and($this->bread->fresh()->stock_quantity)->toBe(39)
        ->and($this->tab->checkouts()->where('status', CheckoutStatus::Completed)->count())->toBe(1);
});

it('lets the merchant take the basket back before the customer confirms', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('putOnTab')
        ->assertSee('Take back')
        ->call('clearBasket')
        ->assertSee('Scan or tap to add.');

    expect($this->tab->checkouts()->sole()->status)->toBe(CheckoutStatus::Cancelled)
        ->and($this->tab->hasEntryAwaiting())->toBeFalse()
        ->and($this->bread->fresh()->stock_quantity)->toBe(40);
});

it('refuses a basket that would go over the agreed limit', function () {
    $this->tab->limit_amount = '30.00';
    $this->tab->save();

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('addProduct', $this->milk->id)
        ->call('putOnTab')
        ->assertSee('That would go over the agreed tab limit.');

    expect($this->tab->openCheckout()->status)->toBe(CheckoutStatus::Open)
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('hides put on trusttab until the customer has agreed the tab', function () {
    $this->tab->agreement_accepted_at = null;
    $this->tab->save();

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->assertSee('Pay Now')
        ->assertSee('Agree a limit first')
        ->assertDontSee('Put on TrustTab')
        ->call('askToPutOnTab')
        ->assertForbidden();
});

it('forbids the merchant from confirming the basket and the customer from sending it', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('putOnTab');

    $checkout = $this->tab->currentTillCheckout();

    expect($this->merchant->can('confirm', $checkout))->toBeFalse()
        ->and($this->customer->can('confirm', $checkout))->toBeTrue()
        ->and($this->stranger->can('confirm', $checkout))->toBeFalse()
        ->and($this->customer->can('putOnTab', $checkout))->toBeFalse();

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->assertSee('Waiting for Sipho Khumalo to confirm this basket')
        ->assertDontSee('This will be added to your TrustTab')
        ->assertDontSee('Not these')
        ->call('confirmCheckout')
        ->assertForbidden();

    Livewire::actingAs($this->stranger)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->assertForbidden();
});

it('counts an awaiting basket toward the limit so a new line cannot sneak over', function () {
    $this->tab->limit_amount = '50.00';
    $this->tab->save();

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('addProduct', $this->milk->id)
        ->call('putOnTab')
        ->assertHasNoErrors();

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->set('description', 'Sugar')
        ->set('amount', '20.00')
        ->call('addEntry')
        ->assertSee('That would go over the agreed tab limit.');

    expect($this->tab->entries()->count())->toBe(0);
});
