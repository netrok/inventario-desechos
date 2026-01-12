@php
    $isEdit = isset($item) && $item?->exists;
@endphp

<div class="space-y-6">

    {{-- Sección: Identificación --}}
    <div>
        <h3 class="text-sm font-semibold text-gray-900">Identificación</h3>
        <p class="text-xs text-gray-500 mt-1">Datos básicos para rastrear el equipo.</p>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- Código (readonly) --}}
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Código</label>
                <input
                    value="{{ $isEdit ? $item->codigo : 'Se genera automáticamente' }}"
                    class="w-full rounded-lg border-gray-200 bg-gray-50 text-sm text-gray-700"
                    readonly
                >
                <div class="mt-1 text-xs text-gray-500">
                    {{ $isEdit ? 'Código asignado.' : 'Se asigna al guardar (ej. ITM-000001).' }}
                </div>
            </div>

            {{-- Serie --}}
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Serie</label>
                <input
                    name="serie"
                    value="{{ old('serie', $item->serie ?? '') }}"
                    placeholder="Ej. SN123456 / o escanea"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('serie') border-rose-300 ring-rose-200 @enderror"
                >
                @error('serie') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    {{-- Sección: Características --}}
    <div>
        <h3 class="text-sm font-semibold text-gray-900">Características</h3>
        <p class="text-xs text-gray-500 mt-1">Marca, modelo y categoría para clasificar.</p>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- Marca --}}
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Marca</label>
                <input
                    name="marca"
                    value="{{ old('marca', $item->marca ?? '') }}"
                    placeholder="Ej. Dell, HP, Lenovo"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('marca') border-rose-300 ring-rose-200 @enderror"
                >
                @error('marca') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
            </div>

            {{-- Modelo --}}
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Modelo</label>
                <input
                    name="modelo"
                    value="{{ old('modelo', $item->modelo ?? '') }}"
                    placeholder="Ej. Latitude 5420"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('modelo') border-rose-300 ring-rose-200 @enderror"
                >
                @error('modelo') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
            </div>

            {{-- Categoría (catálogo) --}}
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1">Categoría</label>

                @if(isset($categorias) && $categorias?->count())
                    <select
                        name="categoria_id"
                        class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('categoria_id') border-rose-300 ring-rose-200 @enderror"
                    >
                        <option value="">— Selecciona —</option>
                        @foreach($categorias as $c)
                            <option value="{{ $c->id }}"
                                @selected((string)old('categoria_id', $item->categoria_id ?? '') === (string)$c->id)>
                                {{ $c->nombre }}
                            </option>
                        @endforeach
                    </select>
                @else
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        No hay categorías cargadas. Crea categorías o ejecuta la migración/command.
                    </div>
                @endif

                @error('categoria_id') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror

                {{-- Mensaje legacy opcional --}}
                @if(isset($item) && empty($item->categoria_id) && !empty($item->categoria))
                    <div class="mt-1 text-xs text-amber-600">
                        Legacy detectado: "{{ $item->categoria }}" (aún no mapeado).
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Sección: Control --}}
    <div>
        <h3 class="text-sm font-semibold text-gray-900">Control</h3>
        <p class="text-xs text-gray-500 mt-1">Estado operativo y ubicación actual.</p>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- Estado --}}
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Estado</label>
                <select
                    name="estado"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('estado') border-rose-300 ring-rose-200 @enderror"
                >
                    @foreach($estados as $e)
                        <option value="{{ $e }}" @selected(old('estado', $item->estado ?? 'DISPONIBLE') === $e)>{{ $e }}</option>
                    @endforeach
                </select>
                @error('estado') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
            </div>

            {{-- Ubicación --}}
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Ubicación</label>
                <select
                    name="ubicacion_id"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('ubicacion_id') border-rose-300 ring-rose-200 @enderror"
                >
                    <option value="">— Sin ubicación —</option>
                    @foreach($ubicaciones as $u)
                        <option value="{{ $u->id }}" @selected((string)old('ubicacion_id', $item->ubicacion_id ?? '') === (string)$u->id)>
                            {{ $u->nombre }}
                        </option>
                    @endforeach
                </select>
                @error('ubicacion_id') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
            </div>

            {{-- Notas --}}
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1">Notas</label>
                <textarea
                    name="notas"
                    rows="4"
                    placeholder="Observaciones, condición física, accesorios incluidos, etc."
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('notas') border-rose-300 ring-rose-200 @enderror"
                >{{ old('notas', $item->notas ?? '') }}</textarea>
                @error('notas') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    {{-- Sección: Foto --}}
    <div>
        <h3 class="text-sm font-semibold text-gray-900">Foto</h3>
        <p class="text-xs text-gray-500 mt-1">Sube una imagen para identificar el equipo.</p>

        <div class="mt-4 flex flex-col md:flex-row gap-4">
            <img
                id="fotoPreview"
                src="{{ isset($item) ? $item->foto_url : asset('images/item-placeholder.png') }}"
                class="h-28 w-28 rounded-xl border border-gray-200 object-cover bg-gray-50"
                alt="Preview"
            >

            <div class="flex-1">
                <input
                    type="file"
                    name="foto"
                    id="fotoInput"
                    accept="image/*"
                    class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-lg file:border-0 file:bg-gray-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-gray-800
                           rounded-lg border border-gray-300 focus:border-gray-900 focus:ring-gray-900
                           @error('foto') border-rose-300 ring-rose-200 @enderror"
                >
                @error('foto') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        id="btnQuitarFoto"
                        class="inline-flex items-center rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                        Quitar preview
                    </button>

                    @if(isset($item) && $item->foto_path)
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                value="1"
                                name="delete_foto"
                                id="deleteFoto"
                                class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                            >
                            Eliminar foto guardada
                        </label>
                    @endif

                    <span class="text-xs text-gray-500">JPG/PNG/WEBP, máx 2MB.</span>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
(() => {
  const input = document.getElementById('fotoInput');
  const img = document.getElementById('fotoPreview');
  const btn = document.getElementById('btnQuitarFoto');
  const placeholder = @json(asset('images/item-placeholder.png'));

  if (!input || !img || !btn) return;

  input.addEventListener('change', (e) => {
    const f = e.target.files && e.target.files[0];
    if (!f) return;
    img.src = URL.createObjectURL(f);
  });

  btn.addEventListener('click', () => {
    input.value = '';
    img.src = placeholder;
  });
})();
</script>
