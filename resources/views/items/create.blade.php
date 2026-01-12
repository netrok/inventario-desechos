<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">

            <div class="flex items-center justify-between gap-3 mb-5">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">Nuevo item</h1>
                    <p class="text-sm text-gray-500">Captura rápida y limpia. El código se genera solo.</p>
                </div>

                <a href="{{ route('items.index') }}"
                   class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm hover:bg-gray-50">
                    ← Volver
                </a>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                {{-- ✅ IMPORTANTE: enctype para subir archivos --}}
                <form method="POST" action="{{ route('items.store') }}" enctype="multipart/form-data" class="p-6">
                    @csrf

                    {{-- tu partial --}}
                    @include('items._form', ['item' => null])

                    {{-- ✅ Campo Foto (si lo quieres aquí, mejor que “form suelto”) --}}
                    <div class="mt-5">
                        <label class="block text-sm font-medium text-gray-700">Foto</label>
                        <input type="file" name="foto" accept="image/*"
                               class="mt-1 block w-full text-sm text-gray-700"/>
                        @error('foto')
                            <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-2 border-t pt-5">
                        <a href="{{ route('items.index') }}"
                           class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50">
                            Cancelar
                        </a>
                        <button class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">
                            Guardar
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
