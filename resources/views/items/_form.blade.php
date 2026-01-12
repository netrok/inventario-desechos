@csrf

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

            {{-- Categoría --}}
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1">Categoría</label>
                <input
                    name="categoria"
                    value="{{ old('categoria', $item->categoria ?? '') }}"
                    placeholder="Ej. Laptop, Impresora, Monitor, Accesorio"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('categoria') border-rose-300 ring-rose-200 @enderror"
                >
                @error('categoria') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
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

</div>
