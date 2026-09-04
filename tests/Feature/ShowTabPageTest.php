<?php

use App\Enums\TabEntryStatus;
use App\Models\PaymentRequest;
use App\Models\Product;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->merchant = User::factory()->merchant('Mama Nandi Spaza')->create();
    $this->customer = User::factory()->create(['name' => 'Sipho Khumalo']);
    $this->tab = Tab::factory()->between($this->merchant, $this->customer)->create();
});

it('shows the tab to both participants', function () {
    TabEntry::factory()->for($this->tab)->confirmed()->amount('140.00')->create();

    $this->actingAs($this->merchant)
        ->get(route('tabs.show', $this->tab))
        ->assertOk()
        ->assertSee('Sipho Khumalo');

    $this->actingAs($this->customer)
        ->get(route('tabs.show', $this->tab))
        ->assertOk()
        ->assertSee('Mama Nandi Spaza');
});

it('shows the balance in rand, never in the settlement currency', function () {
    config()->set('trusttab.money.locale', 'en_ZA');

    TabEntry::factory()->for($this->tab)->confirmed()->amount('140.00')->create();

    $this->actingAs($this->customer)
        ->get(route('tabs.show', $this->tab))
        ->assertOk()
        ->assertSee("R\u{A0}140,00")
        ->assertDontSee('EUR');
});

it('refuses the tab to anyone else', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('tabs.show', $this->tab))
        ->assertForbidden();
});

it('lets the merchant add an entry through the form', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->assertSee('Add to tab')
        ->assertDontSee('What did they take?')
        ->call('askToAddEntry')
        ->assertSee('What did they take?');

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->set('description', 'Bread and milk')
        ->set('amount', '45.50')
        ->call('addEntry')
        ->assertHasNoErrors()
        ->assertSet('description', '');

    $entry = $this->tab->entries()->sole();

    expect($entry->description)->toBe('Bread and milk')
        ->and($entry->amount)->toBe('45.50')
        ->and($entry->status)->toBe(TabEntryStatus::PendingConfirmation)
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('fills the add form from a product on the shelf', function () {
    $bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'selling_price' => '18.00',
    ]);
    Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Milk',
        'selling_price' => '22.00',
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->set('query', 'Bread')
        ->call('askToAddEntry')
        ->assertSee('Bread')
        ->assertDontSee('Milk');

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('selectProduct', $bread->id)
        ->assertSet('description', 'Bread')
        ->assertSet('amount', '18.00')
        ->call('addEntry')
        ->assertHasNoErrors();

    $entry = $this->tab->entries()->sole();

    expect($entry->description)->toBe('Bread')
        ->and($entry->amount)->toBe('18.00');
});

it('does not show another shop\'s product on the shelf', function () {
    Product::factory()->create(['name' => 'Secret soap']);

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('askToAddEntry')
        ->assertDontSee('Secret soap');
});

it('does not let the merchant pick another shop\'s product', function () {
    $foreign = Product::factory()->create(['name' => 'Secret soap']);

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('selectProduct', $foreign->id);
})->throws(ModelNotFoundException::class);

it('rejects an entry with a missing description or bad amount', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->set('description', '')
        ->set('amount', '0')
        ->call('addEntry')
        ->assertHasErrors(['description', 'amount']);

    expect($this->tab->entries()->count())->toBe(0);
});

it('rejects an amount with more than two decimal places', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->set('description', 'Airtime')
        ->set('amount', '10.999')
        ->call('addEntry')
        ->assertHasErrors('amount');
});

it('stops the customer adding entries to their own tab', function () {
    $bread = Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'selling_price' => '18.00',
    ]);

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->assertDontSee('Add to tab')
        ->assertDontSee('What did they take?')
        ->call('askToAddEntry')
        ->assertForbidden();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('selectProduct', $bread->id)
        ->assertForbidden();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->set('description', 'Free groceries')
        ->set('amount', '500.00')
        ->call('addEntry')
        ->assertForbidden();

    expect($this->tab->entries()->count())->toBe(0);
});

it('moves the balance when the customer confirms', function () {
    $entry = TabEntry::factory()->for($this->tab)->amount('45.50')->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('confirm', $entry->id)
        ->assertHasNoErrors();

    expect($this->tab->outstandingBalance())->toBe('45.50');
});

it('keeps the balance flat when the customer disputes', function () {
    $entry = TabEntry::factory()->for($this->tab)->amount('45.50')->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('askToDispute', $entry->id)
        ->assertSee('Not this item?')
        ->set('disputeReason', 'Wrong amount')
        ->call('dispute', $entry->id);

    expect($this->tab->entries()->sole()->status)->toBe(TabEntryStatus::Disputed)
        ->and($this->tab->entries()->sole()->note)->toBe('Wrong amount')
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('stops the merchant confirming their own entry', function () {
    $entry = TabEntry::factory()->for($this->tab)->create();

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('confirm', $entry->id)
        ->assertForbidden();

    expect($entry->fresh()->status)->toBe(TabEntryStatus::PendingConfirmation);
});

it('lets the merchant withdraw a disputed entry', function () {
    $entry = TabEntry::factory()->for($this->tab)->disputed()->create();

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('withdraw', $entry->id)
        ->assertHasNoErrors();

    expect($this->tab->entries()->sole()->status)->toBe(TabEntryStatus::Withdrawn);
});

it('refuses to act on an entry belonging to another tab', function () {
    $foreign = TabEntry::factory()->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('confirm', $foreign->id);
})->throws(ModelNotFoundException::class);

it('does not let a stale screen act on an entry that already moved on', function () {
    $entry = TabEntry::factory()->for($this->tab)->amount('45.50')->create();

    $component = Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab]);

    $component->call('confirm', $entry->id)->assertHasNoErrors();

    $component->call('dispute', $entry->id)->assertForbidden();

    expect($this->tab->entries()->sole()->status)->toBe(TabEntryStatus::Confirmed)
        ->and($this->tab->outstandingBalance())->toBe('45.50');
});

it('asks in the app before sending a momo payment', function () {
    $this->customer->update(['msisdn' => '27723041887']);
    TabEntry::factory()->for($this->tab)->confirmed()->amount('140.00')->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab->fresh(['customer'])])
        ->assertDontSee('Pay this tab?')
        ->call('askToSettle')
        ->assertSee('Pay this tab?')
        ->assertSee('Pay now')
        ->assertSee('072 304 1887');
});

it('closes the pay confirmation without calling momo', function () {
    Http::fake();

    TabEntry::factory()->for($this->tab)->confirmed()->amount('140.00')->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('askToSettle')
        ->call('cancelSettlement')
        ->assertDontSee('Pay this tab?');

    Http::assertNothingSent();
});

it('offers the pay button to the customer only, and only when money is owed', function () {
    TabEntry::factory()->for($this->tab)->confirmed()->amount('140.00')->create();

    $this->actingAs($this->customer)
        ->get(route('tabs.show', $this->tab))
        ->assertSee('Pay with MoMo');

    $this->actingAs($this->merchant)
        ->get(route('tabs.show', $this->tab))
        ->assertDontSee('Pay with MoMo');
});

it('hides the pay button while nothing is confirmed', function () {
    TabEntry::factory()->for($this->tab)->amount('140.00')->create();

    $this->actingAs($this->customer)
        ->get(route('tabs.show', $this->tab))
        ->assertDontSee('Pay with MoMo');
});

it('shows the customer that momo is waiting on them', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        '*/collection/v1_0/requesttopay' => Http::response(status: 202),
    ]);

    TabEntry::factory()->for($this->tab)->confirmed()->amount('140.00')->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('settle')
        ->assertHasNoErrors()
        ->assertSee('Waiting for MoMo')
        ->assertSee('Check your phone for a prompt')
        ->assertDontSee('Pay with MoMo');
});

it('tells the customer in plain words when momo will not take the payment', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        '*/collection/v1_0/requesttopay' => Http::response(['code' => 'PAYER_NOT_FOUND'], 400),
    ]);

    TabEntry::factory()->for($this->tab)->confirmed()->amount('140.00')->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('settle')
        ->assertHasErrors('settlement')
        ->assertSee('not registered for MoMo');

    expect($this->tab->outstandingBalance())->toBe('140.00');
});

it('shows both people why a failed payment left the tab unchanged', function () {
    TabEntry::factory()->for($this->tab)->confirmed()->amount('50.00')->create();
    PaymentRequest::factory()->for($this->tab)->failed('PAYER_NOT_FOUND')->create([
        'amount' => '50.00',
    ]);

    $this->actingAs($this->merchant)
        ->get(route('tabs.show', $this->tab))
        ->assertSee('That payment did not go through')
        ->assertSee('not registered for MoMo')
        ->assertDontSee('Pay with MoMo');

    $this->actingAs($this->customer)
        ->get(route('tabs.show', $this->tab))
        ->assertSee('That payment did not go through')
        ->assertSee('not registered for MoMo')
        ->assertSee('Pay with MoMo');

    expect($this->tab->outstandingBalance())->toBe('50.00');
});

it('shows the customer that momo declined after they sent the prompt', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        '*/collection/v1_0/requesttopay' => Http::response(status: 202),
        '*/collection/v1_0/requesttopay/*' => Http::response([
            'status' => 'FAILED',
            'reason' => 'INTERNAL_PROCESSING_ERROR',
        ]),
    ]);

    TabEntry::factory()->for($this->tab)->confirmed()->amount('50.00')->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('settle')
        ->assertHasNoErrors()
        ->assertSee('Waiting for MoMo')
        ->call('refreshPayment')
        ->assertSee('That payment did not go through')
        ->assertSee('could not complete this payment')
        ->assertDontSee('Waiting for MoMo');

    expect($this->tab->outstandingBalance())->toBe('50.00');
});

it('still tells both people when momo fails without a mapped reason', function () {
    TabEntry::factory()->for($this->tab)->confirmed()->amount('50.00')->create();
    PaymentRequest::factory()->for($this->tab)->failed('UNMAPPED_REASON')->create([
        'amount' => '50.00',
    ]);

    $this->actingAs($this->customer)
        ->get(route('tabs.show', $this->tab))
        ->assertSee('That payment did not go through')
        ->assertSee('MoMo declined this payment (UNMAPPED_REASON)')
        ->assertSee('Nothing was taken and the tab is unchanged');
});

it('does not let the merchant open the pay confirmation', function () {
    TabEntry::factory()->for($this->tab)->confirmed()->amount('140.00')->create();

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('askToSettle')
        ->assertForbidden();
});

it('does not let the merchant raise a payment against their own customer', function () {
    Http::fake();

    TabEntry::factory()->for($this->tab)->confirmed()->amount('140.00')->create();

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('settle')
        ->assertForbidden();

    Http::assertNothingSent();
});

it('clears the balance on screen once momo confirms the payment', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        '*/collection/v1_0/requesttopay' => Http::response(status: 202),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL']),
    ]);

    TabEntry::factory()->for($this->tab)->confirmed()->amount('140.00')->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('settle')
        ->call('refreshPayment')
        ->assertSee('Paid with MoMo')
        ->assertDontSee('Waiting for MoMo');

    expect($this->tab->outstandingBalance())->toBe('0.00');
});
