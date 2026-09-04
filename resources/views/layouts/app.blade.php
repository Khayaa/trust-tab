<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#004f71">
    <title>{{ $title ?? 'TrustTab' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-ink-100 font-sans text-ink-700 antialiased">
    <div class="mx-auto flex min-h-full w-full max-w-lg flex-col bg-white shadow-sm">
        <x-app-nav />

        <main class="flex-1 px-5 py-6">
            {{ $slot }}
        </main>

        {{--
            The MoMo brand guidelines restrict the MoMo logo to the payment step
            of the journey. Anywhere else, only the endorsement wording is
            permitted, so this stays as text.
        --}}
        <footer class="px-4 pb-4">
            <p class="text-center text-[11px] text-ink-400">Supported by MTN MoMo</p>
        </footer>
    </div>
</body>
</html>
