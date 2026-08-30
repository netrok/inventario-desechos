<x-app-layout title="Acerca del sistema">
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Acerca del sistema</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Información sobre el producto.
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-900 text-white">
                            <svg viewBox="0 0 24 24" class="h-6 w-6" fill="currentColor" aria-hidden="true">
                                <path d="M7 3h10v3H7z"/>
                                <path d="M7 9h10v3H7z"/>
                                <path d="M7 15h10v3H7z" opacity=".7"/>
                                <path d="M18 15a3 3 0 1 1 3 3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900">{{ config('app.name') }}</h3>
                    </div>

                    <p class="mt-4 text-sm text-gray-600">
                        Sistema de control, trazabilidad y venta de activos reutilizables.
                    </p>

                    <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="rounded-lg bg-gray-50 p-4">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Diseño y desarrollo</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900">{{ config('app.author') }}</dd>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-4">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Versión</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900">1.0</dd>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-4">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Año</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900">{{ config('app.copyright_year') }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>