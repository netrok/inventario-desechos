@php $isEdit = isset($ubicacion) && $ubicacion?->exists; @endphp

<div>
    <label class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
    <input name="nombre"
           value="{{ old('nombre', $ubicacion->nombre ?? '') }}"
           class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('nombre') border-rose-300 ring-rose-200 @enderror">
    @error('nombre') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
</div>

<div>
    <label class="block text-xs font-medium text-gray-600 mb-1">Descripción</label>
    <textarea name="descripcion" rows="3"
              class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('descripcion') border-rose-300 ring-rose-200 @enderror">{{ old('descripcion', $ubicacion->descripcion ?? '') }}</textarea>
    @error('descripcion') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
</div>

<div class="flex items-center gap-2">
    <input type="checkbox" name="activo" value="1"
           class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
           @checked(old('activo', $ubicacion->activo ?? true))>
    <span class="text-sm text-gray-700">Activo</span>
</div>
