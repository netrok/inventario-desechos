<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php
            $tituloSeccion = trim((string) ($title ?? ''));
        @endphp
        <title>{{ \App\Support\Titulos::componer($tituloSeccion !== '' ? $tituloSeccion : null) }}</title>

        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <link rel="apple-touch-icon" href="{{ asset('favicon.svg') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="font-sans antialiased">
        <div class="flex min-h-screen flex-col bg-gray-100">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white shadow">
                    <div class="max-w-screen-2xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main class="flex-1">
                {{ $slot }}
            </main>

            <!-- Footer (créditos discretos) -->
            <footer class="border-t border-gray-200 bg-white">
                <div class="max-w-screen-2xl mx-auto px-4 py-3 sm:px-6 lg:px-8">
                    <p class="text-center text-xs text-gray-500">
                        {{ config('app.name') }} · Desarrollado por {{ config('app.author') }} · {{ config('app.copyright_year') }}
                        <span aria-hidden="true">·</span>
                        <a href="{{ route('acerca') }}" class="underline hover:text-gray-700">Acerca del sistema</a>
                    </p>
                </div>
            </footer>
        </div>

        @stack('scripts')
    </body>
</html>
