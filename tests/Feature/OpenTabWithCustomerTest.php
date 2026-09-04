<?php

use App\Actions\OpenTabWithCustomer;
use App\Enums\TabStatus;
use App\Models\Tab;
use App\Models\User;
use App\Support\Msisdn;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->merchant = User::factory()->merchant('Mama Nandi Spaza')->create([
        'msisdn' => '27821234567',
    ]);
});

it('opens a tab for a customer the merchant has never seen', function () {
    $tab = app(OpenTabWithCustomer::class)->handle($this->merchant, 'Sipho Khumalo', '072 304 1887');

    $customer = $tab->customer;

    expect($tab->merchant_id)->toBe($this->merchant->id)
        ->and($tab->status)->toBe(TabStatus::Active)
        ->and($customer->name)->toBe('Sipho Khumalo')
        ->and($customer->msisdn)->toBe('27723041887')
        ->and($customer->email)->toBeNull()
        ->and($customer->password)->toBeNull();
});

it('reuses a person who already exists at another shop, and does not rename them', function () {
    $existing = User::factory()->create([
        'name' => 'Sipho Khumalo',
        'msisdn' => '27723041887',
    ]);

    $otherShop = User::factory()->merchant()->create();
    Tab::factory()->between($otherShop, $existing)->create();

    $tab = app(OpenTabWithCustomer::class)->handle($this->merchant, 'That tall guy', '27723041887');

    expect($tab->customer_id)->toBe($existing->id)
        ->and($existing->fresh()->name)->toBe('Sipho Khumalo')
        ->and(User::query()->where('msisdn', '27723041887')->count())->toBe(1)
        ->and(Tab::query()->where('customer_id', $existing->id)->count())->toBe(2);
});

it('returns the same tab when the merchant adds the same number twice', function () {
    $first = app(OpenTabWithCustomer::class)->handle($this->merchant, 'Sipho Khumalo', '27723041887');
    $second = app(OpenTabWithCustomer::class)->handle($this->merchant, 'Sipho Khumalo', '0723041887');

    expect($second->is($first))->toBeTrue()
        ->and(Tab::query()->count())->toBe(1);
});

it('reopens a tab the merchant had closed', function () {
    $customer = User::factory()->create(['msisdn' => '27723041887']);
    $closed = Tab::factory()->closed()->between($this->merchant, $customer)->create();

    $tab = app(OpenTabWithCustomer::class)->handle($this->merchant, 'Sipho Khumalo', '27723041887');

    expect($tab->is($closed))->toBeTrue()
        ->and($tab->status)->toBe(TabStatus::Active);
});

it('refuses the merchant their own number', function () {
    expect(fn () => app(OpenTabWithCustomer::class)->handle($this->merchant, 'Me', '082 123 4567'))
        ->toThrow(ValidationException::class);

    expect(Tab::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(1);
});

it('turns a handwritten south african number into the form momo expects', function (string $typed, string $stored) {
    expect(Msisdn::normalize($typed))->toBe($stored);
})->with([
    'spaces and leading zero' => ['072 304 1887', '27723041887'],
    'plus and country code' => ['+27 72 304 1887', '27723041887'],
    'already international' => ['27723041887', '27723041887'],
    'international prefix' => ['0027723041887', '27723041887'],
]);

it('shows a south african number the way a shopkeeper reads it', function () {
    expect(Msisdn::forDisplay('27723041887'))->toBe('072 304 1887')
        ->and(Msisdn::forDisplay('46733123453'))->toBe('46733123453');
});

it('rejects something that is not a mobile number', function (string $typed) {
    expect(Msisdn::tryNormalize($typed))->toBeNull();
})->with([
    'letters' => ['not-a-number'],
    'too short' => ['08212'],
    'empty' => ['   '],
]);

it('lets a merchant open a tab from the list, and hides the form from a customer', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::tabs')
        ->assertSee('Add a customer')
        ->assertDontSee('Their name')
        ->call('askToAddCustomer')
        ->assertSee('Their name');

    Livewire::actingAs($this->merchant)
        ->test('pages::tabs')
        ->set('customerName', 'Sipho Khumalo')
        ->set('customerMsisdn', '072 304 1887')
        ->call('openTab')
        ->assertHasNoErrors()
        ->assertRedirect(route('tabs.show', Tab::query()->sole()));

    $customer = User::query()->where('msisdn', '27723041887')->sole();

    Livewire::actingAs($customer)
        ->test('pages::tabs')
        ->assertDontSee('Add a customer')
        ->assertSee('Mama Nandi Spaza')
        ->call('askToAddCustomer')
        ->assertForbidden();

    Livewire::actingAs($customer)
        ->test('pages::tabs')
        ->call('openTab')
        ->assertForbidden();
});

it('tells the merchant when the number is not usable', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::tabs')
        ->set('customerName', 'Sipho Khumalo')
        ->set('customerMsisdn', '123')
        ->call('openTab')
        ->assertHasErrors('customerMsisdn');

    expect(Tab::query()->count())->toBe(0);
});

it('does not let a customer invent a tab against a shop', function () {
    $customer = User::factory()->create();

    expect($customer->can('create', Tab::class))->toBeFalse()
        ->and($this->merchant->can('create', Tab::class))->toBeTrue();
});

it('escapes a customer name so it cannot become markup on the list', function () {
    $name = 'Sipho <script>alert(1)</script>';

    app(OpenTabWithCustomer::class)->handle($this->merchant, $name, '27723041887');

    $this->actingAs($this->merchant)
        ->get(route('tabs'))
        ->assertOk()
        ->assertDontSee($name, false)
        ->assertSee('Sipho', false);
});
