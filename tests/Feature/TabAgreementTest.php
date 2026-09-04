<?php

use App\Actions\AcceptTabAgreement;
use App\Actions\AddTabEntry;
use App\Actions\ConfirmTabEntry;
use App\Actions\ProposeTabTerms;
use App\Enums\TabEntryStatus;
use App\Events\TabUpdated;
use App\Exceptions\TabAgreementRequired;
use App\Exceptions\TabLimitExceeded;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->merchant = User::factory()->merchant('Mama Nandi Spaza')->create();
    $this->customer = User::factory()->create(['name' => 'Sipho Khumalo']);
});

it('lets the merchant propose a limit and payday without accepting it for the customer', function () {
    $tab = Tab::factory()->withoutAgreement()->between($this->merchant, $this->customer)->create();

    Event::fake([TabUpdated::class]);

    $tab = app(ProposeTabTerms::class)->handle($tab, $this->merchant, '500.00', 25);

    expect($tab->limit_amount)->toBe('500.00')
        ->and($tab->settlement_day)->toBe(25)
        ->and($tab->agreement_accepted_at)->toBeNull()
        ->and($tab->hasAcceptedAgreement())->toBeFalse();

    Event::assertDispatched(TabUpdated::class, fn (TabUpdated $event) => $event->tabId === $tab->id);
});

it('lets only the customer accept the proposed terms', function () {
    $tab = Tab::factory()->proposed('500.00', 25)->between($this->merchant, $this->customer)->create();

    expect($this->customer->can('acceptAgreement', $tab))->toBeTrue()
        ->and($this->merchant->can('acceptAgreement', $tab))->toBeFalse();

    $tab = app(AcceptTabAgreement::class)->handle($tab);

    expect($tab->hasAcceptedAgreement())->toBeTrue()
        ->and($tab->availableAmount())->toBe('500.00');
});

it('does not let the merchant add to a tab the customer has not agreed', function () {
    $tab = Tab::factory()->proposed()->between($this->merchant, $this->customer)->create();

    expect($this->merchant->can('addEntry', $tab))->toBeFalse();

    expect(fn () => app(AddTabEntry::class)->handle($tab, $this->merchant, [
        'description' => 'Bread',
        'amount' => '18.00',
    ]))->toThrow(TabAgreementRequired::class);

    expect($tab->entries()->count())->toBe(0);
});

it('rejects an add that would go over the agreed limit, including pending items', function () {
    $tab = Tab::factory()->agreed('100.00', 25)->between($this->merchant, $this->customer)->create();

    app(AddTabEntry::class)->handle($tab, $this->merchant, [
        'description' => 'Maize meal',
        'amount' => '80.00',
    ]);

    expect(fn () => app(AddTabEntry::class)->handle($tab, $this->merchant, [
        'description' => 'Eggs',
        'amount' => '30.00',
    ]))->toThrow(TabLimitExceeded::class);

    expect($tab->entries()->count())->toBe(1)
        ->and($tab->outstandingBalance())->toBe('0.00')
        ->and($tab->availableAmount())->toBe('100.00');
});

it('refuses to confirm an entry that would take the tab over its limit', function () {
    $tab = Tab::factory()->agreed('100.00', 25)->between($this->merchant, $this->customer)->create();

    TabEntry::factory()->for($tab)->confirmed()->amount('90.00')->create();
    $pending = TabEntry::factory()->for($tab)->amount('20.00')->create();

    expect(fn () => app(ConfirmTabEntry::class)->handle($pending))
        ->toThrow(TabLimitExceeded::class);

    expect($pending->fresh()->status)->toBe(TabEntryStatus::PendingConfirmation)
        ->and($tab->outstandingBalance())->toBe('90.00');
});

it('clears the customers acceptance when the merchant changes the terms', function () {
    $tab = Tab::factory()->agreed('500.00', 25)->between($this->merchant, $this->customer)->create();

    $tab = app(ProposeTabTerms::class)->handle($tab, $this->merchant, '400.00', 25);

    expect($tab->agreement_accepted_at)->toBeNull()
        ->and($tab->limit_amount)->toBe('400.00')
        ->and($tab->hasAcceptedAgreement())->toBeFalse();
});

it('does not clear acceptance when the merchant saves the same terms again', function () {
    $tab = Tab::factory()->agreed('500.00', 25)->between($this->merchant, $this->customer)->create();
    $acceptedAt = $tab->agreement_accepted_at;

    $tab = app(ProposeTabTerms::class)->handle($tab, $this->merchant, '500.00', 25);

    expect($tab->agreement_accepted_at?->equalTo($acceptedAt))->toBeTrue()
        ->and($tab->hasAcceptedAgreement())->toBeTrue();
});

it('rejects a proposed limit below what is already outstanding', function () {
    $tab = Tab::factory()->agreed('500.00', 25)->between($this->merchant, $this->customer)->create();
    TabEntry::factory()->for($tab)->confirmed()->amount('140.00')->create();

    expect(fn () => app(ProposeTabTerms::class)->handle($tab, $this->merchant, '100.00', 25))
        ->toThrow(ValidationException::class);

    expect($tab->fresh()->limit_amount)->toBe('500.00');
});

it('derives the next payday from the settlement day', function () {
    $this->travelTo(Carbon::parse('2026-09-02'));

    $tab = Tab::factory()->agreed('500.00', 25)->between($this->merchant, $this->customer)->create();

    expect($tab->nextDueDate()->toDateString())->toBe('2026-09-25')
        ->and($tab->settlementDayLabel())->toBe('25th');

    $this->travelTo(Carbon::parse('2026-09-26'));

    expect($tab->nextDueDate()->toDateString())->toBe('2026-10-25');
});

it('uses the last day of a short month when the payday is the 31st', function () {
    $this->travelTo(Carbon::parse('2026-02-01'));

    $tab = Tab::factory()->agreed('500.00', 31)->between($this->merchant, $this->customer)->create();

    expect($tab->nextDueDate()->toDateString())->toBe('2026-02-28');
});

it('opens a tab with proposed terms from the merchant list', function () {
    Livewire::actingAs($this->merchant)
        ->test('pages::tabs')
        ->set('customerName', 'Sipho Khumalo')
        ->set('customerMsisdn', '072 304 1887')
        ->set('limitAmount', '500.00')
        ->set('settlementDay', '25')
        ->call('openTab')
        ->assertHasNoErrors()
        ->assertRedirect(route('tabs.show', Tab::query()->sole()));

    $tab = Tab::query()->sole();

    expect($tab->limit_amount)->toBe('500.00')
        ->and($tab->settlement_day)->toBe(25)
        ->and($tab->agreement_accepted_at)->toBeNull();
});

it('hides add to tab as soon as the merchant changes the terms', function () {
    $tab = Tab::factory()->agreed('500.00', 25)->between($this->merchant, $this->customer)->create();

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $tab])
        ->assertSee('Add to tab')
        ->set('limitAmount', '400.00')
        ->call('proposeTerms')
        ->assertHasNoErrors()
        ->assertDontSee('Add to tab')
        ->assertSee('waiting for agreement');
});

it('lets the customer accept terms on the tab screen', function () {
    $tab = Tab::factory()->proposed('500.00', 25)->between($this->merchant, $this->customer)->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $tab])
        ->assertSee('Agree this tab?')
        ->assertDontSee('Add to tab')
        ->call('acceptAgreement')
        ->assertHasNoErrors()
        ->assertDontSee('Agree this tab?');

    expect($tab->fresh()->hasAcceptedAgreement())->toBeTrue();
});

it('forbids the merchant from accepting their own proposal', function () {
    $tab = Tab::factory()->proposed()->between($this->merchant, $this->customer)->create();

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $tab])
        ->call('acceptAgreement')
        ->assertForbidden();

    expect($tab->fresh()->agreement_accepted_at)->toBeNull();
});

it('forbids the customer from proposing terms', function () {
    $tab = Tab::factory()->withoutAgreement()->between($this->merchant, $this->customer)->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $tab])
        ->call('proposeTerms')
        ->assertForbidden();
});

it('shows the agreed limit and payday to both people once accepted', function () {
    $this->travelTo(Carbon::parse('2026-09-02'));

    $tab = Tab::factory()->agreed('500.00', 25)->between($this->merchant, $this->customer)->create();
    TabEntry::factory()->for($tab)->confirmed()->amount('140.00')->create();

    $this->actingAs($this->merchant)
        ->get(route('tabs.show', $tab))
        ->assertOk()
        ->assertSee('available')
        ->assertSee('due 25 Sep');

    $this->actingAs($this->customer)
        ->get(route('tabs'))
        ->assertOk()
        ->assertSee('due 25 Sep');
});
