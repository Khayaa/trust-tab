<div {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-xl border border-ink-200 bg-ink-50 px-4 py-3']) }} aria-hidden="true">
    <x-skeleton class="size-2.5 shrink-0 rounded-full bg-sunshine-300" />
    <div class="min-w-0 flex-1 space-y-2">
        <x-skeleton class="h-4 w-36" />
        <x-skeleton class="h-3 w-full max-w-xs" />
    </div>
</div>
