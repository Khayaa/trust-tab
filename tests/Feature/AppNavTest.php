<?php

use App\Models\Tab;
use App\Models\User;

beforeEach(function () {
    $this->merchant = User::factory()->merchant('Mama Nandi Spaza')->create(['name' => 'Nandi Dlamini']);
    $this->customer = User::factory()->create(['name' => 'Sipho Khumalo']);
    $this->tab = Tab::factory()->between($this->merchant, $this->customer)->create();
});

it('gives the merchant tabs and products in the main nav', function () {
    $this->actingAs($this->merchant)
        ->get(route('tabs'))
        ->assertOk()
        ->assertSee('aria-label="Main"', false)
        ->assertSee('Tabs')
        ->assertSee('Products')
        ->assertSee('Account menu')
        ->assertSee('Sign out');

    $this->actingAs($this->merchant)
        ->get(route('products'))
        ->assertOk()
        ->assertSee('aria-current="page"', false);
});

it('hides the section nav from a customer who only has tabs', function () {
    $this->actingAs($this->customer)
        ->get(route('tabs'))
        ->assertOk()
        ->assertDontSee('aria-label="Main"', false)
        ->assertDontSee('Products')
        ->assertSee('Sign out');
});
