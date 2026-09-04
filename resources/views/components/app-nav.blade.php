@php
    $user = auth()->user();
    $onTabs = request()->routeIs('tabs', 'tabs.show', 'tabs.till');
    $onProducts = request()->routeIs('products');
    $initials = $user
        ? Str::of($user->name)->explode(' ')->map(fn ($part) => Str::substr($part, 0, 1))->take(2)->implode('')
        : '';
@endphp

<header class="sticky top-0 z-20 bg-momo-800">
    <div class="flex items-center justify-between gap-3 px-4 py-3">
        <a href="{{ route('tabs') }}" wire:navigate class="flex min-w-0 items-center gap-2">
            {{-- Echoes the portal's mark: a deep blue tile with a yellow glyph and base bar. --}}
            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg border-b-4 border-sunshine-400 bg-momo-900 text-xs font-bold text-sunshine-400">TT</span>
            <span class="truncate text-base font-bold tracking-tight text-white">TrustTab</span>
        </a>

        @auth
            <div x-data="{ open: false }" class="relative shrink-0">
                <button type="button"
                        class="flex size-8 items-center justify-center rounded-full bg-momo-900 text-xs font-bold text-sunshine-400 transition hover:bg-momo-950 focus-visible:ring-2 focus-visible:ring-sunshine-400 focus-visible:outline-none"
                        aria-label="Account menu"
                        aria-haspopup="menu"
                        :aria-expanded="open.toString()"
                        aria-controls="account-menu"
                        @click="open = ! open"
                        @keydown.escape.window="open = false">
                    <span aria-hidden="true">{{ $initials }}</span>
                </button>

                <div id="account-menu"
                     x-cloak
                     x-show="open"
                     x-transition
                     @click.outside="open = false"
                     role="menu"
                     aria-label="Account"
                     class="absolute right-0 z-30 mt-2 w-56 rounded-xl border border-ink-200 bg-white p-3 shadow-lg">
                    <p class="truncate text-sm font-semibold text-ink-950">{{ $user->name }}</p>
                    <p class="mt-0.5 text-xs font-semibold text-ink-500">
                        {{ $user->isMerchant() ? 'Merchant' : 'Customer' }}
                    </p>
                    <form method="POST" action="{{ route('logout') }}" class="mt-3">
                        @csrf
                        <button type="submit" role="menuitem"
                                class="w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-ink-700 transition hover:bg-ink-50">
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        @endauth
    </div>

    @if ($user?->isMerchant())
        <nav aria-label="Main" class="flex gap-1 px-2 pb-0">
            <a href="{{ route('tabs') }}" wire:navigate
               @if ($onTabs) aria-current="page" @endif
               wire:current="border-sunshine-400 bg-white/10 text-white"
               @class([
                   'flex-1 rounded-t-lg px-3 py-2.5 text-center text-sm font-semibold transition focus-visible:ring-2 focus-visible:ring-sunshine-400 focus-visible:outline-none',
                   'border-b-2 border-sunshine-400 bg-white/10 text-white' => $onTabs,
                   'text-momo-200 hover:bg-white/5 hover:text-white' => ! $onTabs,
               ])>
                Tabs
            </a>
            <a href="{{ route('products') }}" wire:navigate
               @if ($onProducts) aria-current="page" @endif
               wire:current="border-sunshine-400 bg-white/10 text-white"
               @class([
                   'flex-1 rounded-t-lg px-3 py-2.5 text-center text-sm font-semibold transition focus-visible:ring-2 focus-visible:ring-sunshine-400 focus-visible:outline-none',
                   'border-b-2 border-sunshine-400 bg-white/10 text-white' => $onProducts,
                   'text-momo-200 hover:bg-white/5 hover:text-white' => ! $onProducts,
               ])>
                Products
            </a>
        </nav>
    @endif
</header>
