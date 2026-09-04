<?php

use App\Actions\ConfirmPendingTabEntries;
use App\Enums\TabEntryStatus;
use App\Exceptions\TabLimitExceeded;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->merchant = User::factory()->merchant('Mama Nandi Spaza')->create();
    $this->customer = User::factory()->create(['name' => 'Sipho Khumalo']);
    $this->tab = Tab::factory()->agreed('500.00', 25)->between($this->merchant, $this->customer)->create();
});

it('confirms every waiting line in one go', function () {
    TabEntry::factory()->for($this->tab)->amount('18.00')->create(['description' => 'Bread']);
    TabEntry::factory()->for($this->tab)->amount('22.00')->create(['description' => 'Milk']);
    TabEntry::factory()->for($this->tab)->amount('35.00')->create(['description' => 'Eggs']);

    $confirmed = app(ConfirmPendingTabEntries::class)->handle($this->tab);

    expect($confirmed)->toHaveCount(3)
        ->and($confirmed->every(fn (TabEntry $entry) => $entry->status === TabEntryStatus::Confirmed))->toBeTrue()
        ->and($this->tab->outstandingBalance())->toBe('75.00')
        ->and($this->tab->entries()->awaitingConfirmation()->count())->toBe(0);
});

it('confirms none when the batch would break the limit', function () {
    $this->tab->limit_amount = '100.00';
    $this->tab->save();

    TabEntry::factory()->for($this->tab)->amount('60.00')->create();
    TabEntry::factory()->for($this->tab)->amount('60.00')->create();

    expect(fn () => app(ConfirmPendingTabEntries::class)->handle($this->tab))
        ->toThrow(TabLimitExceeded::class);

    expect($this->tab->entries()->awaitingConfirmation()->count())->toBe(2)
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('lets the customer confirm the waiting lines from the tab screen', function () {
    TabEntry::factory()->for($this->tab)->amount('18.00')->create(['description' => 'Bread']);
    TabEntry::factory()->for($this->tab)->amount('22.00')->create(['description' => 'Milk']);

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->assertSee('Confirm these')
        ->assertSee('You can still open any line')
        ->call('confirmPending')
        ->assertHasNoErrors();

    expect($this->tab->outstandingBalance())->toBe('40.00')
        ->and($this->tab->entries()->awaitingConfirmation()->count())->toBe(0);
});

it('hides the batch confirm from the merchant', function () {
    TabEntry::factory()->for($this->tab)->amount('18.00')->create();
    TabEntry::factory()->for($this->tab)->amount('22.00')->create();

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->assertDontSee('Confirm these')
        ->call('confirmPending')
        ->assertForbidden();
});

it('does not show a batch confirm for a single waiting line', function () {
    TabEntry::factory()->for($this->tab)->amount('18.00')->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->assertSee('Yes, that was me')
        ->assertDontSee('Confirm these');
});
