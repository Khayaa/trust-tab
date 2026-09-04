<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoSessionController extends Controller
{
    /**
     * Sign in as a seeded user without a password so the demo can run the
     * merchant on one device and the customer on another.
     *
     * This hands out a session to anyone who can reach it, so it is hidden
     * entirely unless the demo flag is on.
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        abort_unless(config('trusttab.demo_auth'), 404);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('tabs');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
