<?php

use App\Enums\CheckoutStatus;
use App\Models\Checkout;
use App\Models\PaymentRequest;
use App\Models\Product;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use App\Support\MerchantMorning;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;

beforeEach(function () {
    $this->merchant = User::factory()->merchant('Mama Nandi Spaza')->create();
    $this->customer = User::factory()->create(['name' => 'Sipho Khumalo']);
    $this->tab = Tab::factory()->between($this->merchant, $this->customer)->create();
});

/**
 * @return Collection<int, Tab>
 */
function merchantMorningTabs(User $merchant)
{
    return Tab::query()
        ->forParticipant($merchant)
        ->with(['outstandingEntries', 'paymentRequests'])
        ->withCount([
            'entries as awaiting_count' => fn ($query) => $query->awaitingConfirmation(),
            'entries as disputed_count' => fn ($query) => $query->disputed(),
            'checkouts as awaiting_basket_count' => fn ($query) => $query->where('status', CheckoutStatus::AwaitingConfirmation),
        ])
        ->get();
}

it('shows the merchant what is owed, waiting, low, and taken today', function () {
    TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create(['description' => 'Groceries']);
    TabEntry::factory()->for($this->tab)->amount('75.00')->create(['description' => 'Toiletries']);
    TabEntry::factory()->for($this->tab)->disputed()->amount('18.00')->create(['description' => 'Airtime']);

    Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Washing powder',
        'stock_quantity' => 5,
        'low_stock_threshold' => 8,
    ]);
    Product::factory()->forMerchant($this->merchant)->create([
        'name' => 'Bread',
        'stock_quantity' => 40,
        'low_stock_threshold' => 8,
    ]);
    Product::factory()->forMerchant($this->merchant)->inactive()->create([
        'name' => 'Old stock',
        'stock_quantity' => 1,
        'low_stock_threshold' => 8,
    ]);

    $checkout = Checkout::factory()->forTab($this->tab)->completed('18.00')->create();
    $checkout->tab_amount = '22.00';
    $checkout->save();

    $morning = MerchantMorning::fromTabs($this->merchant, merchantMorningTabs($this->merchant));

    expect($morning->owed)->toBe('90.00')
        ->and($morning->waitingOnCustomer)->toBe(1)
        ->and($morning->disputed)->toBe(1)
        ->and($morning->lowStockCount)->toBe(1)
        ->and($morning->todayTill)->toBe('40.00');

    Livewire::actingAs($this->merchant)
        ->test('pages::tabs')
        ->assertSee('This morning')
        ->assertSee('Mama Nandi Spaza')
        ->assertSee('Owed to you')
        ->assertSee('Waiting')
        ->assertSee('Low stock')
        ->assertSee('Washing powder')
        ->assertDontSee('Bread')
        ->assertDontSee('Old stock')
        ->assertSee('Till today')
        ->assertSee('1 disputed, needs you');
});

it('counts a basket waiting on the customer', function () {
    Checkout::factory()->forTab($this->tab)->awaitingConfirmation('40.00')->create();

    expect(MerchantMorning::fromTabs($this->merchant, merchantMorningTabs($this->merchant))->waitingOnCustomer)
        ->toBe(1);

    Livewire::actingAs($this->merchant)
        ->test('pages::tabs')
        ->assertSee('Basket awaiting their confirmation');
});

it('hides the morning snapshot from the customer', function () {
    TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create();

    Livewire::actingAs($this->customer)
        ->test('pages::tabs')
        ->assertSee('Your tabs')
        ->assertDontSee('This morning')
        ->assertDontSee('Owed to you')
        ->assertDontSee('Till today')
        ->assertDontSee('Low stock')
        ->assertDontSee('On TrustTab')
        ->assertDontSee('MoMo settled')
        ->assertDontSee('Most on the tab')
        ->assertSee('Mama Nandi Spaza');
});

it('does not count another shop owed, stock, or till', function () {
    $other = User::factory()->merchant('Other Spaza')->create();
    $otherTab = Tab::factory()->between($other, User::factory()->create())->create();

    TabEntry::factory()->for($otherTab)->confirmed()->amount('200.00')->create();
    Product::factory()->forMerchant($other)->create([
        'name' => 'Secret soap',
        'stock_quantity' => 1,
        'low_stock_threshold' => 8,
    ]);
    Checkout::factory()->forTab($otherTab)->completed('50.00')->create();

    $morning = MerchantMorning::fromTabs($this->merchant, merchantMorningTabs($this->merchant));

    expect($morning->owed)->toBe('0.00')
        ->and($morning->todayTill)->toBe('0.00')
        ->and($morning->lowStockCount)->toBe(0);

    Livewire::actingAs($this->merchant)
        ->test('pages::tabs')
        ->assertDontSee('Secret soap')
        ->assertDontSee('Other Spaza');
});

it('does not mix tabs where the merchant is the customer into owed or till', function () {
    $other = User::factory()->merchant('Other Spaza')->create();
    $asCustomer = Tab::factory()->between($other, $this->merchant)->create();

    TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create();
    TabEntry::factory()->for($asCustomer)->confirmed()->amount('200.00')->create();
    Checkout::factory()->forTab($asCustomer)->completed('50.00')->create();

    $morning = MerchantMorning::fromTabs($this->merchant, merchantMorningTabs($this->merchant));

    expect($morning->owed)->toBe('90.00')
        ->and($morning->todayTill)->toBe('0.00')
        ->and($morning->tabCount)->toBe(1);
});

it('shows this months TrustTab sales, MoMo, and the lines that sold most', function () {
    TabEntry::factory()->for($this->tab)->confirmed()->amount('18.00')->create(['description' => 'Bread']);
    TabEntry::factory()->for($this->tab)->confirmed()->amount('18.00')->create(['description' => 'Bread']);
    TabEntry::factory()->for($this->tab)->settled()->amount('22.00')->create(['description' => 'Milk']);
    TabEntry::factory()->for($this->tab)->amount('75.00')->create(['description' => 'Washing powder']);
    TabEntry::factory()->for($this->tab)->disputed()->amount('35.00')->create(['description' => 'Eggs']);
    TabEntry::factory()->for($this->tab)->withdrawn()->amount('40.00')->create(['description' => 'Paraffin']);

    $old = TabEntry::factory()->for($this->tab)->confirmed()->amount('110.00')->create(['description' => 'Maize meal']);
    $old->confirmed_at = now()->subMonth();
    $old->save();

    PaymentRequest::factory()->for($this->tab)->successful()->create(['amount' => '50.00']);
    PaymentRequest::factory()->for($this->tab)->failed()->create(['amount' => '80.00']);
    $oldPayment = PaymentRequest::factory()->for($this->tab)->successful()->create(['amount' => '200.00']);
    $oldPayment->completed_at = now()->subMonth();
    $oldPayment->save();

    $morning = MerchantMorning::fromTabs($this->merchant, merchantMorningTabs($this->merchant));

    expect($morning->monthTabSales)->toBe('58.00')
        ->and($morning->monthMomo)->toBe('50.00')
        ->and($morning->topOnTab->pluck('count', 'name')->all())->toBe([
            'Bread' => 2,
            'Milk' => 1,
        ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::tabs')
        ->assertSee('This month')
        ->assertSee('On TrustTab')
        ->assertSee('MoMo settled')
        ->assertSee('Most on the tab')
        ->assertSee('Bread')
        ->assertDontSee('Washing powder')
        ->assertDontSee('Maize meal')
        ->assertDontSee('Paraffin');
});

it('does not count another shops sales or MoMo in this month', function () {
    $other = User::factory()->merchant()->create();
    $otherTab = Tab::factory()->between($other, User::factory()->create())->create();

    TabEntry::factory()->for($otherTab)->confirmed()->amount('200.00')->create(['description' => 'Secret soap']);
    PaymentRequest::factory()->for($otherTab)->successful()->create(['amount' => '80.00']);

    $morning = MerchantMorning::fromTabs($this->merchant, merchantMorningTabs($this->merchant));

    expect($morning->monthTabSales)->toBe('0.00')
        ->and($morning->monthMomo)->toBe('0.00')
        ->and($morning->topOnTab)->toBeEmpty();

    Livewire::actingAs($this->merchant)
        ->test('pages::tabs')
        ->assertDontSee('Secret soap');
});

it('does not mix tabs where the merchant is the customer into this month', function () {
    $other = User::factory()->merchant('Other Spaza')->create();
    $asCustomer = Tab::factory()->between($other, $this->merchant)->create();

    TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create(['description' => 'Bread']);
    TabEntry::factory()->for($asCustomer)->confirmed()->amount('200.00')->create(['description' => 'Airtime']);
    PaymentRequest::factory()->for($asCustomer)->successful()->create(['amount' => '50.00']);

    $morning = MerchantMorning::fromTabs($this->merchant, merchantMorningTabs($this->merchant));

    expect($morning->monthTabSales)->toBe('90.00')
        ->and($morning->monthMomo)->toBe('0.00')
        ->and($morning->topOnTab->pluck('name')->all())->toBe(['Bread']);
});

it('escapes a tab line so it cannot become markup on this month', function () {
    $name = 'Bread <script>alert(1)</script>';

    TabEntry::factory()->for($this->tab)->confirmed()->amount('18.00')->create(['description' => $name]);

    Livewire::actingAs($this->merchant)
        ->test('pages::tabs')
        ->assertDontSee($name, false)
        ->assertSee('Bread', false);
});

it('does not treat a yesterday checkout as till today', function () {
    $checkout = Checkout::factory()->forTab($this->tab)->completed('40.00')->create();
    $checkout->completed_at = now()->subDay();
    $checkout->save();

    expect(MerchantMorning::fromTabs($this->merchant, merchantMorningTabs($this->merchant))->todayTill)
        ->toBe('0.00');
});
