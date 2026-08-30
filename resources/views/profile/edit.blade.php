<x-app-layout title="Perfil">
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Perfil</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Tu información personal y el acceso a tu cuenta.
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="max-w-xl p-6">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="max-w-xl p-6">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

        </div>
    </div>
</x-app-layout>