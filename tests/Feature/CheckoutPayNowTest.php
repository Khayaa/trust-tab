<?php

use App\Actions\RequestTabSettlement;
use App\Enums\CheckoutStatus;
use App\Enums\PaymentRequestStatus;
use App\Enums\TabEntryStatus;
use App\Models\Product;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Ramsey\Uuid\Uuid;

beforeEach(function () {
    $this->merchant = User::factory()->merchant('Mama Nandi Spaza')->create();
    $this->customer = User::factory()->create([
        'name' => 'Sipho Khumalo',
        'msisdn' => '27723041887',
    ]);
    $this->tab = Tab::factory()->between($this->merchant, $this->customer)->create();

    $this->bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'barcode' => '6001234000018',
        'selling_price' => '18.00',
        'stock_quantity' => 40,
    ]);

    $this->fakeMomo = function (mixed $requestToPay, array $status = ['status' => 'SUCCESSFUL']) {
        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            '*/collection/v1_0/requesttopay' => $requestToPay,
            '*/collection/v1_0/requesttopay/*' => Http::response($status),
        ]);
    };
});

it('collects the basket with momo and takes stock only after a successful get', function () {
    ($this->fakeMomo)(Http::response(status: 202));

    TabEntry::factory()->for($this->tab)->confirmed()->amount('140.00')->create();

    $component = Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('askToPayNow')
        ->assertSet('confirmingPayNow', true)
        ->assertSee('072 304 1887')
        ->call('payNow')
        ->assertHasNoErrors()
        ->assertSee('Waiting for MoMo');

    $checkout = $this->tab->currentTillCheckout();

    expect($checkout->status)->toBe(CheckoutStatus::AwaitingMomo)
        ->and($checkout->momo_amount)->toBe('18.00')
        ->and($checkout->tab_amount)->toBe('0.00')
        ->and($this->bread->fresh()->stock_quantity)->toBe(40)
        ->and($this->tab->outstandingBalance())->toBe('140.00')
        ->and($this->tab->entries()->count())->toBe(1);

    $payment = $checkout->paymentRequests()->sole();

    expect($payment->amount)->toBe('18.00')
        ->and($payment->status)->toBe(PaymentRequestStatus::Pending)
        ->and($payment->checkout_id)->toBe($checkout->id)
        ->and(Uuid::fromString($payment->reference_id)->getFields()->getVersion())->toBe(4);

    $component->call('refreshPayment')
        ->assertSee('Paid with MoMo')
        ->assertDontSee('Waiting for MoMo')
        ->assertDontSee('Pay Now');

    expect($checkout->fresh()->status)->toBe(CheckoutStatus::Completed)
        ->and($checkout->fresh()->completed_at)->not->toBeNull()
        ->and($this->bread->fresh()->stock_quantity)->toBe(39)
        ->and($this->tab->outstandingBalance())->toBe('140.00')
        ->and($this->tab->entries()->where('status', TabEntryStatus::Settled)->count())->toBe(0);
});

it('does not treat a 202 as paid', function () {
    ($this->fakeMomo)(Http::response(status: 202), ['status' => 'PENDING']);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('payNow')
        ->call('refreshPayment')
        ->assertSee('Waiting for MoMo');

    expect($this->tab->currentTillCheckout()->status)->toBe(CheckoutStatus::AwaitingMomo)
        ->and($this->bread->fresh()->stock_quantity)->toBe(40);
});

it('reuses the in-flight till payment instead of requesting a second debit', function () {
    ($this->fakeMomo)(Http::response(status: 202), ['status' => 'PENDING']);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('payNow')
        ->call('payNow');

    expect($this->tab->currentTillCheckout()->paymentRequests()->count())->toBe(1);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/requesttopay'));
    Http::assertSentCount(2);
});

it('leaves the basket open and the shelf untouched when momo refuses the request', function () {
    ($this->fakeMomo)(Http::response(['code' => 'PAYER_NOT_FOUND'], 400));

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('payNow')
        ->assertSee('not registered for MoMo');

    expect($this->tab->openCheckout()->status)->toBe(CheckoutStatus::Open)
        ->and($this->tab->openCheckout()->total())->toBe('18.00')
        ->and($this->bread->fresh()->stock_quantity)->toBe(40)
        ->and($this->tab->paymentRequests()->sole()->status)->toBe(PaymentRequestStatus::Failed);
});

it('reopens the basket and takes no stock when get status is failed', function () {
    ($this->fakeMomo)(Http::response(status: 202), [
        'status' => 'FAILED',
        'reason' => 'PAYER_REJECTED',
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('payNow')
        ->call('refreshPayment')
        ->assertSee('That payment did not go through')
        ->assertSee('declined the MoMo prompt')
        ->assertSee('Pay Now');

    expect($this->tab->openCheckout()->status)->toBe(CheckoutStatus::Open)
        ->and($this->bread->fresh()->stock_quantity)->toBe(40)
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('freezes the basket while momo is in flight', function () {
    ($this->fakeMomo)(Http::response(status: 202), ['status' => 'PENDING']);

    $milk = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Milk',
        'selling_price' => '22.00',
        'stock_quantity' => 24,
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('payNow')
        ->call('addProduct', $milk->id)
        ->assertSee('Waiting for MoMo. This basket is frozen.');

    expect($this->tab->currentTillCheckout()->lines)->toHaveCount(1)
        ->and($this->tab->checkouts()->open()->count())->toBe(0);
});

it('lets the merchant collect before the customer agrees the tab', function () {
    ($this->fakeMomo)(Http::response(status: 202));

    $this->tab->agreement_accepted_at = null;
    $this->tab->save();

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('payNow')
        ->call('refreshPayment')
        ->assertSee('Paid with MoMo');

    expect($this->tab->fresh()->openCheckout())->toBeNull()
        ->and($this->bread->fresh()->stock_quantity)->toBe(39);
});

it('does not let a till payment settle the tab or hide the tab pay button', function () {
    ($this->fakeMomo)(Http::response(status: 202));

    TabEntry::factory()->for($this->tab)->confirmed()->amount('140.00')->create();

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('payNow')
        ->call('refreshPayment');

    $this->actingAs($this->customer)
        ->get(route('tabs.show', $this->tab))
        ->assertSee('Pay with MoMo')
        ->assertDontSee('Nothing went on the tab');

    expect($this->tab->outstandingBalance())->toBe('140.00');
});

it('does not reuse a till payment as a tab settlement', function () {
    ($this->fakeMomo)(Http::response(status: 202), ['status' => 'PENDING']);

    TabEntry::factory()->for($this->tab)->confirmed()->amount('50.00')->create();

    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('payNow');

    $settlement = app(RequestTabSettlement::class)->handle($this->tab);

    expect($settlement->checkout_id)->toBeNull()
        ->and($settlement->amount)->toBe('50.00')
        ->and($this->tab->paymentRequests()->count())->toBe(2);
});

it('is safe to apply a successful till result twice', function () {
    ($this->fakeMomo)(Http::response(status: 202));

    $component = Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->call('addProduct', $this->bread->id)
        ->call('payNow');

    $component->call('refreshPayment');
    $component->call('refreshPayment');

    expect($this->bread->fresh()->stock_quantity)->toBe(39)
        ->and($this->tab->checkouts()->where('status', CheckoutStatus::Completed)->count())->toBe(1);
});

it('hides pay now from the customer', function () {
    Livewire::actingAs($this->customer)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->assertForbidden();
});

it('does not offer pay now on an empty basket', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::checkout', ['tab' => $this->tab])
        ->assertDontSee('Pay Now')
        ->assertDontSee('TrustTab')
        ->assertDontSee('Split')
        ->call('askToPayNow')
        ->assertSet('confirmingPayNow', false);
});
