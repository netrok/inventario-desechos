<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php
            $tituloSeccion = trim((string) ($title ?? ''));
        @endphp
        <title>{{ \App\Support\Titulos::componer($tituloSeccion !== '' ? $tituloSeccion : 'Iniciar sesión') }}</title>

        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <link rel="apple-touch-icon" href="{{ asset('favicon.svg') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="font-sans text-gray-900 antialiased">
        <div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-teal-900 via-teal-800 to-emerald-900 px-4 py-10">

            <div class="w-full max-w-md">
                {{-- Marca --}}
                <div class="flex flex-col items-center text-center mb-8">
                    <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/20 shadow-lg">
                        <svg viewBox="0 0 64 64" class="h-9 w-9" aria-hidden="true">
                            <path d="M20 14h24v10H20z" fill="#ffffff"/>
                            <path d="M20 27h24v10H20z" fill="#ffffffcc"/>
                            <path d="M20 40h24v10H20z" fill="#ffffff99"/>
                            <path d="M47 44a8 8 0 1 1-2-14" stroke="#ffd23f" stroke-width="4" fill="none" stroke-linecap="round"/>
                            <path d="M47 30h6v6" stroke="#ffd23f" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h1 class="mt-4 text-2xl font-bold tracking-tight text-white">Inventario ReUse</h1>
                    <p class="mt-1.5 text-sm text-teal-100/90">
                        Control de activos · Reutilización · Trazabilidad
                    </p>
                </div>

                {{-- Tarjeta del formulario --}}
                <div class="rounded-2xl bg-white p-8 shadow-2xl">
                    {{ $slot }}
                </div>

                <p class="mt-6 text-center text-xs text-teal-100/70">
                    Acceso exclusivo para personal autorizado
                </p>

                <p class="mt-3 text-center text-xs text-teal-100/70">
                    {{ config('app.name') }} · Desarrollado por {{ config('app.author') }} · {{ config('app.copyright_year') }}
                </p>
            </div>
        </div>

        @stack('scripts')
    </body>
</html>
