<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <div class="mb-5">
                <h1 class="text-xl font-semibold text-gray-900">Editar ubicación</h1>
                <p class="text-sm text-gray-500">Actualiza la información de la ubicación.</p>
            </div>

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                @include('ubicaciones._form', ['ubicacion' => $ubicacion])
            </div>
        </div>
    </div>
</x-app-layout>
