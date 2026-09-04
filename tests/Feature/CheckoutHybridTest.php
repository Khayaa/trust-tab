<?php

use App\Enums\CheckoutStatus;
use App\Enums\PaymentRequestStatus;
use App\Enums\TabEntryStatus;
use App\Models\Product;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->merchant = User::factory()->merchant('Mama Nandi Spaza')->create();
    $this->customer = User::factory()->create([
        'name' => 'Sipho Khumalo',
        'msisdn' => '27723041887',
    ]);
    $this->tab = Tab::factory()->between($this->merchant, $this->customer)->create();

    $this->bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
    ]);
    $this->milk = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Milk',
        'selling_price' => '22.00',
        'stock_quantity' => 24,
    ]);

    $this->fakeMomo = function (mixed $requestToPay, array $status = ['status' => 'SUCCESSFUL']) {
        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            '*/collection/v1_0/requesttopay' => $requestToPay,
            '*/collection/v1_0/requesttopay/*' => Http::response($status),
        ]);
    };
});

it('holds a split basket until the customer confirms it', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('addProduct', $this->milk->id)
        ->call('askToSplit')
        ->assertSet('confirmingSplit', true)
        ->assertSee('Split this basket?')
        ->set('momoAmount', '18.00')
        ->call('split')
        ->assertHasNoErrors()
        ->assertSee('Waiting for Sipho Khumalo to confirm')
        ->assertSee('MoMo will collect')
        ->assertDontSee('Split this basket?');

    $checkout = $this->tab->currentTillCheckout();

    expect($checkout->status)->toBe(CheckoutStatus::AwaitingConfirmation)
        ->and($checkout->isHybrid())->toBeTrue()
        ->and($checkout->momo_amount)->toBe('18.00')
        ->and($checkout->tab_amount)->toBe('22.00')
        ->and($this->bread->fresh()->stock_quantity)->toBe(40)
        ->and($this->tab->outstandingBalance())->toBe('0.00')
        ->and($this->tab->entries()->count())->toBe(0);
});

it('collects only the momo share after the customer accepts the split', function () {
    ($this->fakeMomo)(Http::response(status: 202), ['status' => 'PENDING']);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('addProduct', $this->milk->id)
        ->set('momoAmount', '18.00')
        ->call('split');

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->assertSee('Pay some now, the rest on your TrustTab')
        ->call('confirmCheckout')
        ->assertHasNoErrors()
        ->assertSee('Waiting for MoMo')
        ->assertSee('goes on the tab when MoMo confirms');

    $checkout = $this->tab->currentTillCheckout();
    $payment = $checkout->paymentRequests()->sole();

    expect($checkout->status)->toBe(CheckoutStatus::AwaitingMomo)
        ->and($checkout->tab_amount)->toBe('22.00')
        ->and($payment->amount)->toBe('18.00')
        ->and($payment->status)->toBe(PaymentRequestStatus::Pending)
        ->and($this->bread->fresh()->stock_quantity)->toBe(40)
        ->and($this->tab->outstandingBalance())->toBe('0.00')
        ->and($this->tab->entries()->count())->toBe(0);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/requesttopay')
        && ($request['amount'] ?? null) === '18.00');
});

it('writes the tab remainder and takes stock only after momo succeeds', function () {
    ($this->fakeMomo)(Http::response(status: 202));

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('addProduct', $this->milk->id)
        ->set('momoAmount', '18.00')
        ->call('split');

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('confirmCheckout');

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('refreshPayment')
        ->assertSee('Paid with MoMo');

    $checkout = $this->tab->checkouts()->sole();
    $entry = $this->tab->entries()->sole();

    expect($checkout->status)->toBe(CheckoutStatus::Completed)
        ->and($this->bread->fresh()->stock_quantity)->toBe(39)
        ->and($this->milk->fresh()->stock_quantity)->toBe(23)
        ->and($this->tab->outstandingBalance())->toBe('22.00')
        ->and($entry->status)->toBe(TabEntryStatus::Confirmed)
        ->and($entry->amount)->toBe('22.00')
        ->and($entry->checkout_id)->toBe($checkout->id)
        ->and($entry->description)->toBe('Bread, Milk');
});

it('reopens the basket and writes nothing when momo fails after they confirmed', function () {
    ($this->fakeMomo)(Http::response(status: 202), [
        'status' => 'FAILED',
        'reason' => 'PAYER_REJECTED',
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('addProduct', $this->milk->id)
        ->set('momoAmount', '18.00')
        ->call('split');

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('confirmCheckout');

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('refreshPayment')
        ->assertSee('That payment did not go through')
        ->assertSee('Split');

    expect($this->tab->openCheckout()->status)->toBe(CheckoutStatus::Open)
        ->and($this->tab->openCheckout()->total())->toBe('40.00')
        ->and($this->bread->fresh()->stock_quantity)->toBe(40)
        ->and($this->tab->entries()->count())->toBe(0)
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('refuses a split that would put too much on the tab', function () {
    $this->tab->limit_amount = '20.00';
    $this->tab->save();

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('addProduct', $this->milk->id)
        ->set('momoAmount', '18.00')
        ->call('split')
        ->assertSee('That would go over the agreed tab limit.');

    expect($this->tab->openCheckout()->status)->toBe(CheckoutStatus::Open)
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('rejects a split that is all momo or all tab', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('addProduct', $this->milk->id)
        ->set('momoAmount', '40.00')
        ->call('split')
        ->assertSee('Pay some with MoMo and put the rest on the tab.');

    expect($this->tab->openCheckout()->status)->toBe(CheckoutStatus::Open);
});

it('hides split until the customer has agreed the tab', function () {
    $this->tab->agreement_accepted_at = null;
    $this->tab->save();

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->assertSee('Pay Now')
        ->assertDontSee('Split')
        ->call('askToSplit')
        ->assertForbidden();
});

it('counts a hybrid remainder toward the limit while momo is in flight', function () {
    ($this->fakeMomo)(Http::response(status: 202), ['status' => 'PENDING']);

    $this->tab->limit_amount = '30.00';
    $this->tab->save();

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('addProduct', $this->milk->id)
        ->set('momoAmount', '18.00')
        ->call('split');

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('confirmCheckout');

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->set('description', 'Sugar')
        ->set('amount', '15.00')
        ->call('addEntry')
        ->assertSee('That would go over the agreed tab limit.');

    expect($this->tab->entries()->count())->toBe(0)
        ->and($this->tab->currentTillCheckout()->status)->toBe(CheckoutStatus::AwaitingMomo);
});

it('does not let pay now charge the full basket after a split is waiting', function () {
    ($this->fakeMomo)(Http::response(status: 202), ['status' => 'PENDING']);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('addProduct', $this->milk->id)
        ->set('momoAmount', '18.00')
        ->call('split')
        ->call('payNow')
        ->assertForbidden();

    expect($this->tab->currentTillCheckout()->status)->toBe(CheckoutStatus::AwaitingConfirmation)
        ->and($this->tab->paymentRequests()->count())->toBe(0);
});
