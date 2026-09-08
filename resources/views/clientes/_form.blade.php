@php
    $cliente = $cliente ?? null;
@endphp

<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
        <h3 class="text-sm font-semibold text-gray-900">Datos del cliente</h3>

        <div>
            <x-input-label for="tipo" value="Tipo" />
            <select id="tipo" name="tipo" required
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                @foreach($tipos as $t)
                    <option value="{{ $t }}" @selected(old('tipo', $cliente->tipo ?? 'PERSONA') === $t)>{{ $t }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('tipo')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="nombre" value="Nombre *" />
            <x-text-input id="nombre" name="nombre" class="mt-1 block w-full" value="{{ old('nombre', $cliente->nombre ?? '') }}" required />
            <x-input-error :messages="$errors->get('nombre')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="rfc" value="RFC" />
            <x-text-input id="rfc" name="rfc" class="mt-1 block w-full uppercase" value="{{ old('rfc', $cliente->rfc ?? '') }}" maxlength="20" />
            <x-input-error :messages="$errors->get('rfc')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="telefono" value="Teléfono" />
            <x-text-input id="telefono" name="telefono" class="mt-1 block w-full" value="{{ old('telefono', $cliente->telefono ?? '') }}" maxlength="30" />
            <x-input-error :messages="$errors->get('telefono')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="email" value="Email" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full lowercase" value="{{ old('email', $cliente->email ?? '') }}" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
        <h3 class="text-sm font-semibold text-gray-900">Complemento</h3>

        <div>
            <x-input-label for="direccion" value="Dirección" />
            <textarea id="direccion" name="direccion" rows="3"
                      class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">{{ old('direccion', $cliente->direccion ?? '') }}</textarea>
            <x-input-error :messages="$errors->get('direccion')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="notas" value="Notas" />
            <textarea id="notas" name="notas" rows="3"
                      class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">{{ old('notas', $cliente->notas ?? '') }}</textarea>
            <x-input-error :messages="$errors->get('notas')" class="mt-2" />
        </div>

        @if($cliente)
            <div>
                <x-input-label value="Estado" />
                <p class="mt-1 text-sm text-gray-700">
                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $cliente->activo ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                        {{ $cliente->activo ? 'ACTIVO' : 'INACTIVO' }}
                    </span>
                </p>
            </div>
        @endif
    </div>
</div>

@if(\App\Support\CreditoAcceso::puedeConfigurar(Auth::user()))
    <div class="mt-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
        <div>
            <h3 class="text-sm font-semibold text-gray-900">Crédito</h3>
            <p class="text-xs text-gray-500">
                El crédito habilitado permite posteriormente vender con saldo pendiente.
                El límite y plazo serán revalidados por el servidor al realizar la venta.
            </p>
        </div>

        <label class="flex items-start gap-2 text-sm text-gray-700">
            <input type="hidden" name="credito_habilitado" value="0">
            <input type="checkbox" name="credito_habilitado" value="1"
                   @checked((bool) old('credito_habilitado', $cliente->credito_habilitado ?? false))
                   class="mt-0.5 rounded border-gray-300 text-gray-900 focus:ring-gray-900">
            <span>Habilitar crédito</span>
        </label>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="limite_credito" value="Límite de crédito ($)" />
                <x-text-input id="limite_credito" name="limite_credito" type="number" step="0.01" min="0"
                              class="mt-1 block w-full"
                              value="{{ old('limite_credito', $cliente->limite_credito ?? 0) }}" />
                <x-input-error :messages="$errors->get('limite_credito')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="dias_credito" value="Días de crédito" />
                <x-text-input id="dias_credito" name="dias_credito" type="number" min="1"
                              class="mt-1 block w-full"
                              value="{{ old('dias_credito', $cliente->dias_credito ?? '') }}" />
                <x-input-error :messages="$errors->get('dias_credito')" class="mt-2" />
            </div>
        </div>
    </div>
@endif
