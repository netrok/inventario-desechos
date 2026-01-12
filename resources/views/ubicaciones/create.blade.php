<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">Nueva ubicación</h1>
                    <p class="text-sm text-gray-500">Ej: Almacén, Taller, Sucursal GDL.</p>
                </div>
                <a href="{{ route('ubicaciones.index') }}" class="rounded-lg border px-3 py-2 text-sm hover:bg-gray-50">← Volver</a>
            </div>

            <div class="rounded-2xl border bg-white shadow-sm">
                <form method="POST" action="{{ route('ubicaciones.store') }}" class="p-6 space-y-4">
                    @csrf
                    @include('ubicaciones._form', ['ubicacion' => null])

                    <div class="pt-4 border-t flex justify-end gap-2">
                        <a href="{{ route('ubicaciones.index') }}" class="rounded-lg border px-4 py-2 text-sm hover:bg-gray-50">Cancelar</a>
                        <button class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">Guardar</button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
