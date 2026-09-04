<?php

use App\Http\Controllers\Auth\DemoSessionController;
use App\Http\Controllers\MomoCallbackController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tabs');

/**
 * MoMo posts here from its own infrastructure, so there is no session and no
 * CSRF token. The handler treats the body as untrusted and confirms the outcome
 * with MoMo before anything settles.
 */
Route::put('/momo/callback', MomoCallbackController::class)->name('momo.callback');
Route::post('/momo/callback', MomoCallbackController::class);

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'pages::login')->name('login');
    Route::post('/demo-login/{user}', [DemoSessionController::class, 'store'])->name('demo-login.store');
});

Route::middleware('auth')->group(function () {
    Route::livewire('/tabs', 'pages::tabs')->name('tabs');
    Route::livewire('/tabs/{tab}', 'pages::show-tab')->name('tabs.show');
    Route::livewire('/tabs/{tab}/till', 'pages::checkout')->name('tabs.till');
    Route::livewire('/products', 'pages::products')->name('products');

    Route::post('/logout', [DemoSessionController::class, 'destroy'])->name('logout');
});
