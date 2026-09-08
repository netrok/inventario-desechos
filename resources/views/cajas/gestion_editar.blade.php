<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Editar caja {{ $caja->codigo }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    El código es la identidad estable de la caja: no cambia. La baja normal es desactivarla.
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
            @if($caja->sesionesAbiertas->isNotEmpty())
                <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Esta caja tiene una sesión abierta ({{ $caja->sesionesAbiertas->first()->folio }}).
                    No puede desactivarse hasta que se cierre.
                </div>
            @endif

            <form method="POST" action="{{ route('cajas.gestion.update', $caja) }}"
                  class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
                @csrf
                @method('PUT')

                <div class="space-y-1">
                    <label class="block text-sm font-semibold text-gray-900">Código</label>
                    <input type="text" value="{{ $caja->codigo }}" disabled
                           class="w-full rounded-lg border-gray-200 bg-gray-50 font-mono text-sm text-gray-500">
                </div>

                <div class="space-y-1">
                    <label for="nombre" class="block text-sm font-semibold text-gray-900">Nombre *</label>
                    <input type="text" id="nombre" name="nombre" value="{{ old('nombre', $caja->nombre) }}" required maxlength="100"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                    <x-input-error :messages="$errors->get('nombre')" class="mt-1" />
                </div>

                <div class="space-y-1">
                    <label for="descripcion" class="block text-sm font-semibold text-gray-900">Descripción (opcional)</label>
                    <textarea id="descripcion" name="descripcion" rows="3" maxlength="1500"
                              class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">{{ old('descripcion', $caja->descripcion) }}</textarea>
                    <x-input-error :messages="$errors->get('descripcion')" class="mt-1" />
                </div>

                <div class="space-y-1">
                    <label for="usuario_asignado_id" class="block text-sm font-semibold text-gray-900">Usuario operador</label>
                    @if($caja->sesionesAbiertas->isNotEmpty())
                        <input type="hidden" name="usuario_asignado_id" value="{{ $caja->usuario_asignado_id }}">
                    @endif
                    <select id="usuario_asignado_id" name="usuario_asignado_id"
                            @if($caja->sesionesAbiertas->isNotEmpty()) disabled class="w-full rounded-lg border-gray-200 bg-gray-50 text-sm text-gray-500" @else class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900" @endif>
                        <option value="">— Sin asignar —</option>
                        @foreach($operadores as $operador)
                            <option value="{{ $operador->id }}"
                                @selected((string) old('usuario_asignado_id', $caja->usuario_asignado_id) === (string) $operador->id)>
                                {{ $operador->name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500">
                        @if($caja->sesionesAbiertas->isNotEmpty())
                            Bloqueado: la caja tiene una sesión abierta. Ciérrala antes de reasignarla.
                        @else
                            Solo usuarios con permiso para abrir caja. Requerido si la caja está activa.
                        @endif
                    </p>
                    <x-input-error :messages="$errors->get('usuario_asignado_id')" class="mt-1" />
                </div>

                <div class="flex items-center gap-2">
                    <input type="hidden" name="activa" value="{{ $caja->sesionesAbiertas->isNotEmpty() ? '1' : '0' }}">
                    <input type="checkbox" id="activa" name="activa" value="1"
                           {{ $caja->activa ? 'checked' : '' }}
                           @if($caja->sesionesAbiertas->isNotEmpty()) disabled @endif
                           class="rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                    <label for="activa" class="text-sm font-semibold text-gray-900">Caja activa</label>
                    <span class="text-xs text-gray-500">
                        @if($caja->sesionesAbiertas->isNotEmpty())
                            Bloqueada: hay una sesión abierta.
                        @else
                            Las cajas inactivas no aparecen al abrir sesión.
                        @endif
                    </span>
                    <x-input-error :messages="$errors->get('activa')" class="mt-1" />
                </div>

                <div>
                    <button type="submit"
                            class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                        Guardar cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
