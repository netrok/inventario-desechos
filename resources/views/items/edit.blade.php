<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">

            <div class="flex items-center justify-between gap-3 mb-5">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">Editar item</h1>
                    <p class="text-sm text-gray-500">{{ $item->codigo }}</p>
                </div>

                <div class="flex gap-2">
                    <a href="{{ route('items.show', $item) }}"
                       class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm hover:bg-gray-50">
                        Ver
                    </a>
                    <a href="{{ route('items.index') }}"
                       class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm hover:bg-gray-50">
                        ← Volver
                    </a>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                <form method="POST"
                      action="{{ route('items.update', $item) }}"
                      enctype="multipart/form-data"
                      class="p-6">
                    @csrf
                    @method('PUT')

                    {{-- ✅ Partial con TODO (incluye Foto) --}}
                    @include('items._form', [
                        'item' => $item,
                        'estados' => $estados ?? \App\Models\Item::ESTADOS,
                        'ubicaciones' => $ubicaciones ?? collect(),
                        'categorias' => $categorias ?? collect(),
                    ])

                    <div class="mt-6 flex items-center justify-end gap-2 border-t border-gray-100 pt-5">
                        <a href="{{ route('items.show', $item) }}"
                           class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50">
                            Cancelar
                        </a>

                        <button type="submit"
                                class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">
                            Guardar cambios
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
