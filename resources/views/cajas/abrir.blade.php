<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Abrir caja</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Una caja (y un operador) pueden tener una sola sesión abierta a la vez.
                </p>
            </div>

            <a href="{{ route('cajas.index') }}"
               class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                ← Volver a Caja
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8 space-y-5">

            @if($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-disc ps-5 space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($cajas->isEmpty())
                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800">
                    No hay cajas activas disponibles. Pide a un administrador que registre una caja física
                    (permiso <strong>cajas.configurar</strong>).
                </div>
            @else
                <form method="POST" action="{{ route('cajas.abrir.store') }}"
                      class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
                    @csrf

                    <div>
                        <x-input-label for="caja_id" value="Caja física" />
                        <select name="caja_id" id="caja_id" required
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                            @foreach($cajas as $caja)
                                <option value="{{ $caja->id }}" @selected(old('caja_id') == $caja->id)>
                                    {{ $caja->codigo }} · {{ $caja->nombre }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('caja_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="fondo_inicial" value="Fondo inicial (monto físico en el cajón)" />
                        <input
                            id="fondo_inicial"
                            name="fondo_inicial"
                            type="number"
                            step="0.01"
                            min="0"
                            required
                            value="{{ old('fondo_inicial', '0.00') }}"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                        >
                        <x-input-error :messages="$errors->get('fondo_inicial')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="observaciones_apertura" value="Observaciones (opcional)" />
                        <textarea
                            id="observaciones_apertura"
                            name="observaciones_apertura"
                            rows="3"
                            maxlength="1000"
                            placeholder="Cuadre o notas de la apertura…"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                        >{{ old('observaciones_apertura') }}</textarea>
                        <x-input-error :messages="$errors->get('observaciones_apertura')" class="mt-2" />
                    </div>

                    <button type="submit"
                            class="w-full rounded-lg bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                        Abrir sesión de caja
                    </button>
                </form>
            @endif

            <div class="rounded-2xl border border-gray-200 bg-white p-5 text-sm text-gray-600 space-y-2">
                <p class="font-semibold text-gray-900">Antes de abrir</p>
                <p>La apertura registra el fondo inicial del cajón. Este monto es la base del corte ciego al cerrar la sesión.</p>
                <p>Mientras tengas la sesión abierta, las ventas del POS registran los pagos contra esta caja.</p>
            </div>
        </div>
    </div>
</x-app-layout>