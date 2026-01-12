@php
    /** @var \App\Models\Ubicacion|null $ubicacion */
    $isEdit = isset($ubicacion) && $ubicacion;
@endphp

<form
    method="POST"
    action="{{ $isEdit ? route('ubicaciones.update', $ubicacion) : route('ubicaciones.store') }}"
    class="space-y-6"
>
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

    {{-- Nombre --}}
    <div>
        <label class="block text-sm font-medium text-gray-700">Nombre</label>
        <input
            name="nombre"
            value="{{ old('nombre', $ubicacion->nombre ?? '') }}"
            class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
            placeholder="Ej. Bodega Principal"
            required
        >
        @error('nombre')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    {{-- Descripción --}}
    <div>
        <label class="block text-sm font-medium text-gray-700">Descripción (opcional)</label>
        <textarea
            name="descripcion"
            rows="3"
            class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
            placeholder="Detalles / referencia / notas"
        >{{ old('descripcion', $ubicacion->descripcion ?? '') }}</textarea>
        @error('descripcion')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    {{-- Activo --}}
    <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
        <div>
            <p class="text-sm font-medium text-gray-900">Activo</p>
            <p class="text-xs text-gray-500">Si está inactivo, puedes evitar asignaciones nuevas.</p>
        </div>

        {{-- Truco clásico: si no se marca, manda 0 --}}
        <input type="hidden" name="activo" value="0">
        <label class="inline-flex items-center gap-2">
            <input
                type="checkbox"
                name="activo"
                value="1"
                class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                {{ old('activo', ($ubicacion->activo ?? true)) ? 'checked' : '' }}
            >
            <span class="text-sm text-gray-700">Sí</span>
        </label>
    </div>
    @error('activo')
        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
    @enderror

    {{-- Botones --}}
    <div class="flex items-center justify-end gap-2 pt-2">
        <a
            href="{{ route('ubicaciones.index') }}"
            class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50"
        >
            Cancelar
        </a>

        <button
            class="inline-flex items-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black"
        >
            {{ $isEdit ? 'Guardar cambios' : 'Crear ubicación' }}
        </button>
    </div>
</form>
