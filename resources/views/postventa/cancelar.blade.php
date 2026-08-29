<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Cancelar venta {{ $venta->folio }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Reversa TOTAL de la venta. La venta y sus detalles se conservan como historial.
                </p>
            </div>

            <a href="{{ route('ventas.show', $venta) }}"
               class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                ← Volver al detalle
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 space-y-5">

            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <strong>Atención:</strong> esta operación revierte <strong>TODOS</strong> los equipos de la venta a
                <strong>DISPONIBLE</strong>. Solo es posible si la venta está ACTIVA y no tiene operaciones postventa previas.
                El motivo es obligatorio y queda registrado con el usuario y la fecha/hora.
            </div>

            @if($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Resumen de la venta a cancelar --}}
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-3">Código</th>
                            <th class="px-5 py-3">Equipo</th>
                            <th class="px-5 py-3 text-right">Precio (histórico)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($venta->detalles as $detalle)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 font-semibold text-gray-900">{{ $detalle->item->codigo }}</td>
                                <td class="px-5 py-3 text-gray-700">
                                    {{ collect([$detalle->item->marca, $detalle->item->modelo])->filter()->implode(' · ') ?: $detalle->item->serie ?: 'Sin descripción' }}
                                </td>
                                <td class="px-5 py-3 text-right font-semibold text-gray-900">{{ number_format((float) $detalle->precio, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="2" class="px-5 py-3 text-right text-sm font-semibold text-gray-700">Importe revertido</td>
                            <td class="px-5 py-3 text-right text-base font-bold text-gray-900">{{ $totalFormateado }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Formulario con confirmación fuerte y motivo obligatorio --}}
            <form method="POST" action="{{ route('ventas.cancelar.store', $venta) }}" class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
                @csrf

                <div>
                    <x-input-label for="motivo" value="Motivo de cancelación (obligatorio)" />
                    <textarea
                        id="motivo"
                        name="motivo"
                        rows="3"
                        required
                        minlength="5"
                        maxlength="2000"
                        placeholder="Indica el motivo de la reversa total…"
                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('motivo') border-rose-300 ring-rose-200 @enderror"
                    >{{ old('motivo') }}</textarea>
                    <x-input-error :messages="$errors->get('motivo')" class="mt-2" />
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="confirma" value="1" required class="rounded border-gray-300">
                        Confirmo la cancelación total de {{ $venta->detalles->count() }} equipo(s) por {{ $totalFormateado }}
                    </label>

                    <div class="flex gap-2">
                        <a href="{{ route('ventas.show', $venta) }}"
                           class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                            Volver
                        </a>
                        <button type="submit" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-rose-700">
                            Cancelar venta
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>