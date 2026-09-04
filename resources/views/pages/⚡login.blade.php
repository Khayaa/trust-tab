<?php

use App\Actions\SendLoginOtp;
use App\Actions\VerifyLoginOtp;
use App\Auth\LoginOtp;
use App\Models\User;
use App\Support\Msisdn;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Sign in to TrustTab')]
class extends Component
{
    public string $msisdn = '';

    public string $code = '';

    public bool $codeSent = false;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public bool $showEmailForm = false;

    public function sendCode(SendLoginOtp $action): void
    {
        $this->validate(['msisdn' => 'required|string|max:20']);

        $this->msisdn = $action->handle($this->msisdn);
        $this->codeSent = true;
        $this->reset('code');
        $this->resetValidation();
    }

    public function verifyCode(VerifyLoginOtp $action): void
    {
        $this->code = preg_replace('/\D+/', '', $this->code) ?? '';

        $this->validate([
            'msisdn' => 'required|string|max:20',
            'code' => 'required|digits:6',
        ]);

        $user = $action->handle($this->msisdn, $this->code);

        Auth::login($user, $this->remember);
        session()->regenerate();

        $this->redirectRoute('tabs', navigate: true);
    }

    public function login(): void
    {
        $this->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $this->ensureEmailIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->emailThrottleKey());

            /**
             * Deliberately does not say which of the two was wrong, so the form
             * cannot be used to discover who has an account here.
             */
            throw ValidationException::withMessages([
                'email' => 'Those details do not match our records.',
            ]);
        }

        RateLimiter::clear($this->emailThrottleKey());

        session()->regenerate();

        $this->redirectRoute('tabs', navigate: true);
    }

    /**
     * Five tries a minute per email and address. Without this the form is an
     * open invitation to guess passwords, and a tab is a record of what someone
     * owes, which is not something to leave guessable.
     */
    protected function ensureEmailIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->emailThrottleKey(), maxAttempts: 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->emailThrottleKey());

        throw ValidationException::withMessages([
            'email' => "Too many attempts. Try again in {$seconds} seconds.",
        ]);
    }

    protected function emailThrottleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $normalized = $this->codeSent ? Msisdn::tryNormalize($this->msisdn) : null;

        return [
            'revealedCode' => $normalized ? app(LoginOtp::class)->revealed($normalized) : null,
            'demoPeople' => config('trusttab.demo_auth')
                ? User::query()->with('merchantProfile')->orderByDesc('id')->get()
                : collect(),
        ];
    }
};
?>

<div class="space-y-8 py-2">
    <div class="space-y-1.5">
        <h1 class="text-2xl font-bold tracking-tight text-ink-950">Sign in</h1>
        <p class="text-sm text-ink-500">Use the MoMo number the shop saved for you.</p>
    </div>

    <form wire:submit="{{ $codeSent ? 'verifyCode' : 'sendCode' }}" class="space-y-5 rounded-2xl border border-ink-200 bg-white p-5 shadow-sm">
        <div class="space-y-1.5">
            <label for="msisdn" class="block text-xs font-semibold text-ink-700">MoMo number</label>
            <input wire:model="msisdn" id="msisdn" type="tel" inputmode="tel" autocomplete="tel" required
                   @disabled($codeSent)
                   placeholder="072 304 1887"
                   class="block w-full rounded-xl border border-ink-200 px-4 py-3.5 text-sm outline-none transition focus:border-momo-800 focus:ring-1 focus:ring-momo-800 disabled:bg-ink-50">
            @error('msisdn')
                <p class="pt-0.5 text-xs font-medium text-red-600">{{ $message }}</p>
            @enderror
        </div>

        @if ($codeSent)
            <div class="space-y-1.5">
                <label for="code" class="block text-xs font-semibold text-ink-700">One-time code</label>
                <input wire:model="code" id="code" type="text" inputmode="numeric" autocomplete="one-time-code" required
                       maxlength="6" autofocus
                       class="block w-full rounded-xl border border-ink-200 px-4 py-3.5 text-center text-lg font-semibold tracking-[0.4em] outline-none transition focus:border-momo-800 focus:ring-1 focus:ring-momo-800">
                @error('code')
                    <p class="pt-0.5 text-xs font-medium text-red-600">{{ $message }}</p>
                @enderror
            </div>

            @if ($revealedCode)
                <div class="rounded-xl border border-sunshine-300 bg-sunshine-50 px-4 py-3">
                    <p class="text-xs font-semibold text-ink-700">Demo code — this would arrive as an SMS</p>
                    <p class="mt-1 font-mono text-2xl font-bold tracking-[0.3em] text-ink-950">{{ $revealedCode }}</p>
                </div>
            @endif

            <label class="flex items-center gap-2.5 pt-0.5 text-xs text-ink-600">
                <input wire:model="remember" type="checkbox" class="size-4 rounded border-ink-300 text-momo-800 focus:ring-momo-800">
                Keep me signed in on this phone
            </label>
        @endif

        @if (! $codeSent)
            <div wire:loading.delay wire:target="sendCode">
                <x-skeleton.banner />
            </div>
        @endif

        <button type="submit"
                class="w-full rounded-xl bg-sunshine-400 px-4 py-3.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
            @if ($codeSent)
                <span wire:loading.remove wire:target="verifyCode">Sign in</span>
                <span wire:loading wire:target="verifyCode">Checking the code…</span>
            @else
                <span wire:loading.remove wire:target="sendCode">Send a code</span>
                <span wire:loading wire:target="sendCode">Sending…</span>
            @endif
        </button>

        @if ($codeSent)
            <button type="button" wire:click="$set('codeSent', false)" class="w-full text-center text-xs font-semibold text-momo-800">
                Use a different number
            </button>
        @endif
    </form>

    <div class="space-y-4">
        <button type="button" wire:click="$toggle('showEmailForm')" class="w-full text-center text-xs font-semibold text-ink-500">
            {{ $showEmailForm ? 'Hide email sign in' : 'Shop owner? Sign in with email' }}
        </button>

        @if ($showEmailForm)
            <form wire:submit="login" class="space-y-5 rounded-2xl border border-ink-200 bg-white p-5 shadow-sm">
                <div class="space-y-1.5">
                    <label for="email" class="block text-xs font-semibold text-ink-700">Email</label>
                    <input wire:model="email" id="email" type="email" autocomplete="username" required
                           class="block w-full rounded-xl border border-ink-200 px-4 py-3.5 text-sm outline-none transition focus:border-momo-800 focus:ring-1 focus:ring-momo-800">
                    @error('email')
                        <p class="pt-0.5 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-1.5">
                    <label for="password" class="block text-xs font-semibold text-ink-700">Password</label>
                    <input wire:model="password" id="password" type="password" autocomplete="current-password" required
                           class="block w-full rounded-xl border border-ink-200 px-4 py-3.5 text-sm outline-none transition focus:border-momo-800 focus:ring-1 focus:ring-momo-800">
                    @error('password')
                        <p class="pt-0.5 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex items-center gap-2.5 pt-0.5 text-xs text-ink-600">
                    <input wire:model="remember" type="checkbox" class="size-4 rounded border-ink-300 text-momo-800 focus:ring-momo-800">
                    Keep me signed in on this phone
                </label>

                <button type="submit"
                        class="w-full rounded-xl bg-sunshine-400 px-4 py-3.5 text-sm font-bold text-black transition hover:bg-sunshine-500 data-loading:pointer-events-none data-loading:opacity-50">
                    <span wire:loading.remove wire:target="login">Sign in</span>
                    <span wire:loading wire:target="login">Signing in…</span>
                </button>
            </form>
        @endif
    </div>

    @if ($demoPeople->isNotEmpty())
        {{--
            Only rendered while trusttab.demo_auth is on, so the shortcut cannot
            appear anywhere real. The POST it submits to checks the same flag.
        --}}
        <ul class="space-y-2.5 border-t border-ink-200 pt-8">
            @foreach ($demoPeople as $person)
                <li wire:key="person-{{ $person->id }}">
                    <form method="POST" action="{{ route('demo-login.store', $person) }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-3.5 rounded-xl border border-ink-200 bg-white px-4 py-3.5 text-left transition hover:border-momo-800 hover:bg-momo-50">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-momo-800 text-sm font-bold text-white">
                                {{ Str::of($person->name)->explode(' ')->map(fn ($part) => Str::substr($part, 0, 1))->take(2)->implode('') }}
                            </span>
                            <span class="min-w-0 flex-1 space-y-0.5">
                                <span class="block truncate text-sm font-semibold text-ink-950">{{ $person->name }}</span>
                                <span class="block truncate text-xs text-ink-500">
                                    {{ $person->merchantProfile?->business_name ?? 'Customer' }}
                                </span>
                            </span>
                            <span class="shrink-0 text-xs font-bold text-momo-800">Sign in</span>
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</div>
