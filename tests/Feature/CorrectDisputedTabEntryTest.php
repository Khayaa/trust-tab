<?php

use App\Actions\CorrectDisputedTabEntry;
use App\Enums\TabEntryStatus;
use App\Exceptions\InvalidTabEntryTransition;
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

it('withdraws the disputed amount and posts a pending correction', function () {
    $original = TabEntry::factory()->for($this->tab)->disputed()->amount('42.00')->create([
        'description' => 'Milk',
        'note' => 'Wrong amount',
    ]);

    $correction = app(CorrectDisputedTabEntry::class)->handle($original, $this->merchant, [
        'description' => 'Milk',
        'amount' => '22.00',
    ]);

    expect($original->fresh()->status)->toBe(TabEntryStatus::Withdrawn)
        ->and($original->fresh()->amount)->toBe('42.00')
        ->and($correction->status)->toBe(TabEntryStatus::PendingConfirmation)
        ->and($correction->amount)->toBe('22.00')
        ->and($correction->corrects_entry_id)->toBe($original->id)
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('refuses to edit a line that was never disputed', function () {
    $entry = TabEntry::factory()->for($this->tab)->confirmed()->amount('42.00')->create();

    expect(fn () => app(CorrectDisputedTabEntry::class)->handle($entry, $this->merchant, [
        'description' => 'Milk',
        'amount' => '22.00',
    ]))->toThrow(InvalidTabEntryTransition::class);

    expect($entry->fresh()->amount)->toBe('42.00')
        ->and($this->tab->entries()->count())->toBe(1);
});

it('refuses a correction that would break the agreed limit', function () {
    TabEntry::factory()->for($this->tab)->confirmed()->amount('490.00')->create();
    $disputed = TabEntry::factory()->for($this->tab)->disputed()->amount('42.00')->create();

    expect(fn () => app(CorrectDisputedTabEntry::class)->handle($disputed, $this->merchant, [
        'description' => 'Milk',
        'amount' => '22.00',
    ]))->toThrow(TabLimitExceeded::class);

    expect($disputed->fresh()->status)->toBe(TabEntryStatus::Disputed)
        ->and($this->tab->entries()->count())->toBe(2);
});

it('lets the merchant post a correction from the tab screen', function () {
    $original = TabEntry::factory()->for($this->tab)->disputed()->amount('42.00')->create([
        'description' => 'Milk',
        'note' => 'Wrong amount',
    ]);

    Livewire::actingAs($this->merchant)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->assertSee('Post the right amount')
        ->assertSee('Reason: Wrong amount')
        ->call('askToCorrect', $original->id)
        ->assertSee('The disputed line stays on the ledger')
        ->set('correctionAmount', '22.00')
        ->call('correct')
        ->assertHasNoErrors()
        ->assertSee('Correction of Milk')
        ->assertSee('Corrected to Milk');

    expect($original->fresh()->amount)->toBe('42.00')
        ->and($original->fresh()->status)->toBe(TabEntryStatus::Withdrawn)
        ->and($this->tab->entries()->awaitingConfirmation()->sole()->amount)->toBe('22.00');
});

it('does not let the customer post a correction', function () {
    $entry = TabEntry::factory()->for($this->tab)->disputed()->amount('42.00')->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->call('askToCorrect', $entry->id)
        ->assertForbidden();
});
