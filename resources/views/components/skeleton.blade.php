{{--
    A pulse bar for in-flight Livewire work. Wordless on purpose: wire:loading
    markup stays in the HTML, so a sentence here would show up in every test.
--}}
<div {{ $attributes->merge(['class' => 'animate-pulse rounded-md bg-ink-200']) }} aria-hidden="true"></div>
