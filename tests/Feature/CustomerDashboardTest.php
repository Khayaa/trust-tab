<?php

use App\Enums\CheckoutStatus;
use App\Models\Checkout;
use App\Models\PaymentRequest;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use App\Support\CustomerMorning;
use App\Support\TrustHistory;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;

beforeEach(function () {
    $this->merchant = User::factory()->merchant('Mama Nandi Spaza')->create();
    $this->customer = User::factory()->create(['name' => 'Sipho Khumalo']);
    $this->tab = Tab::factory()->agreed('500.00', 25)->between($this->merchant, $this->customer)->create();
});

/**
 * @return Collection<int, Tab>
 */
function customerMorningTabs(User $customer)
{
    return Tab::query()
        ->forParticipant($customer)
        ->with(['merchant.merchantProfile', 'outstandingEntries', 'paymentRequests'])
        ->withCount([
            'entries as awaiting_count' => fn ($query) => $query->awaitingConfirmation(),
            'entries as disputed_count' => fn ($query) => $query->disputed(),
            'checkouts as awaiting_basket_count' => fn ($query) => $query->where('status', CheckoutStatus::AwaitingConfirmation),
        ])
        ->get();
}

it('shows the customer what they owe, what waits, and the next payday', function () {
    $this->travelTo('2026-09-01 08:00:00');

    TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create();
    TabEntry::factory()->for($this->tab)->amount('75.00')->create();

    $trustTabs = CustomerMorning::fromTabs($this->customer, customerMorningTabs($this->customer));

    expect($trustTabs->owed)->toBe('90.00')
        ->and($trustTabs->waitingOnYou)->toBe(1)
        ->and($trustTabs->paydayLabel())->toBe('Due 25 Sep')
        ->and($trustTabs->shouldRemind())->toBeFalse();

    Livewire::actingAs($this->customer)
        ->test('pages::tabs')
        ->assertSee('My TrustTabs')
        ->assertSee('You owe')
        ->assertSee('Waiting')
        ->assertSee('Need you')
        ->assertSee('Payday')
        ->assertSee('Due 25 Sep')
        ->assertDontSee('This morning')
        ->assertDontSee('Owed to you')
        ->assertDontSee('Till today')
        ->assertDontSee('Low stock')
        ->assertSee('Trust History')
        ->assertSee('This is not a score')
        ->assertDontSee('creditworthy')
        ->assertDontSee('credit score')
        ->assertSee('Mama Nandi Spaza');
});

it('counts a basket waiting on the customer', function () {
    Checkout::factory()->forTab($this->tab)->awaitingConfirmation('40.00')->create();

    expect(CustomerMorning::fromTabs($this->customer, customerMorningTabs($this->customer))->waitingOnYou)
        ->toBe(1);

    Livewire::actingAs($this->customer)
        ->test('pages::tabs')
        ->assertSee('Basket awaiting your confirmation');
});

it('hides My TrustTabs from a merchant who is not a customer', function () {
    TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create();

    Livewire::actingAs($this->merchant)
        ->test('pages::tabs')
        ->assertSee('This morning')
        ->assertDontSee('My TrustTabs')
        ->assertDontSee('You owe')
        ->assertDontSee('Trust History');
});

it('does not mix tabs the customer owns as a merchant into what they owe', function () {
    $other = User::factory()->create();
    $asMerchant = Tab::factory()->agreed()->between($this->customer, $other)->create();

    $this->customer->merchantProfile()->create(['business_name' => 'Sipho Corner']);

    TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create();
    TabEntry::factory()->for($asMerchant)->confirmed()->amount('200.00')->create();

    $trustTabs = CustomerMorning::fromTabs($this->customer, customerMorningTabs($this->customer));

    expect($trustTabs->owed)->toBe('90.00')
        ->and($trustTabs->tabCount)->toBe(1);
});

it('reminds the customer the day before payday', function () {
    $this->travelTo('2026-09-24 08:00:00');

    TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create();

    $trustTabs = CustomerMorning::fromTabs($this->customer, customerMorningTabs($this->customer));

    expect($trustTabs->shouldRemind())->toBeTrue()
        ->and($trustTabs->paydayLabel())->toBe('Due tomorrow')
        ->and($trustTabs->reminderWhen())->toBe('tomorrow')
        ->and($trustTabs->reminderAmount())->toBe('90.00')
        ->and($trustTabs->reminderShop())->toBe('Mama Nandi Spaza');

    Livewire::actingAs($this->customer)
        ->test('pages::tabs')
        ->assertSee('Due tomorrow')
        ->assertSee('Mama Nandi Spaza is due tomorrow')
        ->assertSee('Pay with MoMo');
});

it('reminds the customer on payday', function () {
    $this->travelTo('2026-09-25 08:00:00');

    TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create();

    $trustTabs = CustomerMorning::fromTabs($this->customer, customerMorningTabs($this->customer));

    expect($trustTabs->shouldRemind())->toBeTrue()
        ->and($trustTabs->paydayLabel())->toBe('Due today')
        ->and($trustTabs->reminderWhen())->toBe('today');

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->assertSee('Due today')
        ->assertSee('Mama Nandi Spaza is due today');
});

it('counts tab settlements through MoMo and which were on time', function () {
    $this->travelTo('2026-09-20 08:00:00');

    PaymentRequest::factory()->for($this->tab)->successful()->create(['amount' => '90.00']);

    $this->travelTo('2026-09-26 08:00:00');

    PaymentRequest::factory()->for($this->tab)->successful()->create(['amount' => '50.00']);
    PaymentRequest::factory()->for($this->tab)->failed()->create(['amount' => '80.00']);

    $checkout = Checkout::factory()->forTab($this->tab)->completed('40.00')->create();
    PaymentRequest::factory()->for($this->tab)->successful()->create([
        'amount' => '40.00',
        'checkout_id' => $checkout->id,
    ]);

    TabEntry::factory()->for($this->tab)->disputed()->amount('18.00')->create();

    $history = TrustHistory::fromTabs($this->customer, customerMorningTabs($this->customer));

    expect($history->settlements)->toBe(2)
        ->and($history->momoSettled)->toBe('140.00')
        ->and($history->paidOnTime)->toBe(1)
        ->and($history->openDisputes)->toBe(1);

    Livewire::actingAs($this->customer)
        ->test('pages::tabs')
        ->assertSee('Trust History')
        ->assertSee('Settled with MoMo')
        ->assertSee('Paid by the agreed day')
        ->assertSee('Open disputes')
        ->assertSee('This is not a score')
        ->assertDontSee('creditworthy');
});

it('does not count another customers settlements or the merchants shop', function () {
    $stranger = User::factory()->create();
    $otherTab = Tab::factory()->agreed()->between($this->merchant, $stranger)->create();
    PaymentRequest::factory()->for($otherTab)->successful()->create(['amount' => '200.00']);

    $asMerchant = Tab::factory()->agreed()->between($this->customer, User::factory()->create())->create();
    $this->customer->merchantProfile()->create(['business_name' => 'Sipho Corner']);
    PaymentRequest::factory()->for($asMerchant)->successful()->create(['amount' => '80.00']);

    $history = TrustHistory::fromTabs($this->customer, customerMorningTabs($this->customer));

    expect($history->settlements)->toBe(0)
        ->and($history->momoSettled)->toBe('0.00')
        ->and($history->paidOnTime)->toBe(0);
});

it('does not remind when nothing is outstanding', function () {
    $this->travelTo('2026-09-24 08:00:00');

    $trustTabs = CustomerMorning::fromTabs($this->customer, customerMorningTabs($this->customer));

    expect($trustTabs->owed)->toBe('0.00')
        ->and($trustTabs->shouldRemind())->toBeFalse()
        ->and($trustTabs->paydayLabel())->toBe('Nothing due');

    Livewire::actingAs($this->customer)
        ->test('pages::tabs')
        ->assertSee('Nothing due')
        ->assertDontSee('is due tomorrow');
});
