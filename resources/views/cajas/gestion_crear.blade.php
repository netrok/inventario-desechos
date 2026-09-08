<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Crear caja</h2>
                <p class="mt-1 text-sm text-gray-600">
                    El código (CAJ-XXXXXX) se asigna automáticamente y no se puede indicar manualmente.
                </p>
            </div>
            <a href="{{ route('cajas.gestion') }}"
               class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                ← Configuración de cajas
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-screen-lg px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('cajas.gestion.store') }}"
                  class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
                @csrf

                <div class="space-y-1">
                    <label for="nombre" class="block text-sm font-semibold text-gray-900">Nombre *</label>
                    <input type="text" id="nombre" name="nombre" value="{{ old('nombre') }}" required maxlength="100"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                           placeholder="Ej. Caja Principal del local">
                    <x-input-error :messages="$errors->get('nombre')" class="mt-1" />
                </div>

                <div class="space-y-1">
                    <label for="descripcion" class="block text-sm font-semibold text-gray-900">Descripción (opcional)</label>
                    <textarea id="descripcion" name="descripcion" rows="3" maxlength="1500"
                              class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                              placeholder="Ubicación física, etiqueta, observaciones…">{{ old('descripcion') }}</textarea>
                    <x-input-error :messages="$errors->get('descripcion')" class="mt-1" />
                </div>

                <div class="space-y-1">
                    <label for="usuario_asignado_id" class="block text-sm font-semibold text-gray-900">Usuario operador</label>
                    <select id="usuario_asignado_id" name="usuario_asignado_id"
                            class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                        <option value="">— Sin asignar —</option>
                        @foreach($operadores as $operador)
                            <option value="{{ $operador->id }}" @selected(old('usuario_asignado_id') == $operador->id)>
                                {{ $operador->name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500">Solo usuarios con permiso para abrir caja. Requerido si la caja está activa.</p>
                    <x-input-error :messages="$errors->get('usuario_asignado_id')" class="mt-1" />
                </div>

                <div class="flex items-center gap-2">
                    <input type="hidden" name="activa" value="0">
                    <input type="checkbox" id="activa" name="activa" value="1" checked
                           class="rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                    <label for="activa" class="text-sm font-semibold text-gray-900">Caja activa</label>
                    <span class="text-xs text-gray-500">Las cajas inactivas no aparecen al abrir sesión.</span>
                    <x-input-error :messages="$errors->get('activa')" class="mt-1" />
                </div>

                <div>
                    <button type="submit"
                            class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                        Crear caja
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
