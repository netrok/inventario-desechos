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
                {{-- ✅ IMPORTANTE: enctype para subir archivos --}}
                <form method="POST"
                      action="{{ route('items.update', $item) }}"
                      enctype="multipart/form-data"
                      class="p-6">
                    @csrf
                    @method('PUT')

                    @include('items._form', ['item' => $item])

                    {{-- ✅ Preview foto actual + input foto (si NO está en _form) --}}
                    <div class="mt-5">
                        <label class="block text-sm font-medium text-gray-700">Foto</label>

                        <div class="mt-2 flex items-center gap-3">
                            @if($item->foto_path)
                                <img src="{{ asset('storage/'.$item->foto_path) }}"
                                     class="h-20 w-20 rounded-lg object-cover border border-gray-200"
                                     alt="foto">
                            @else
                                <div class="h-20 w-20 rounded-lg border border-dashed border-gray-300 bg-gray-50"></div>
                            @endif

                            <div class="flex-1">
                                <input type="file" name="foto" accept="image/*"
                                       class="block w-full text-sm text-gray-700"/>
                                @error('foto')
                                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                                @enderror
                                <p class="mt-1 text-xs text-gray-500">JPG/PNG/WEBP · máx 4 MB</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-2 border-t pt-5">
                        <a href="{{ route('items.show', $item) }}"
                           class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50">
                            Cancelar
                        </a>
                        <button class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">
                            Guardar cambios
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
