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

                @php
                    $importeCancelacionCentavos = \App\Support\Money::aCentavos((string) $venta->total);
                    $saldoCcCentavos = $venta->cuentaPorCobrar?->saldo_centavos ?? 0;
                    $reduccionCcCentavos = min($saldoCcCentavos, $importeCancelacionCentavos);
                    $restanteCcCentavos = $importeCancelacionCentavos - $reduccionCcCentavos;
                    $asignacionAbonos = [];
                    foreach ($abonosReembolsoUi as $abono) {
                        if ($restanteCcCentavos <= 0) break;
                        $disponible = (int) $abono['disponible_centavos'];
                        if ($disponible <= 0) continue;
                        $tomar = min($disponible, $restanteCcCentavos);
                        $asignacionAbonos[$abono['id']] = $tomar;
                        $restanteCcCentavos -= $tomar;
                    }
                    $efectivoEnPlan = false;
                    foreach ($asignacionAbonos as $id => $centavos) {
                        if (collect($abonosReembolsoUi)->firstWhere('id', $id)['metodo'] === 'EFECTIVO') $efectivoEnPlan = true;
                    }
                @endphp

                @if($creditoPostventa)
                    <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 space-y-3">
                        <div>
                            <h3 class="text-sm font-bold text-indigo-900">
                                Aplicación deuda-primero (crédito)
                            </h3>
                            <p class="mt-1 text-xs text-indigo-800">
                                Esta venta tiene una Cuenta por Cobrar. El importe revertido se aplica
                                PRIMERO a reducir la deuda pendiente y <strong>solo el sobrante</strong>
                                se entrega como reembolso monetario (pagado por el mismo medio original).
                            </p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-sm">
                            <div class="rounded-lg border border-indigo-100 bg-white px-3 py-2">
                                <div class="text-[11px] text-indigo-600">Importe revertido</div>
                                <div class="font-bold text-gray-900">{{ $totalFormateado }}</div>
                            </div>
                            <div class="rounded-lg border border-indigo-100 bg-white px-3 py-2">
                                <div class="text-[11px] text-indigo-600">Reducción de deuda</div>
                                <div class="font-bold text-gray-900">{{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($reduccionCcCentavos)) }}</div>
                            </div>
                            <div class="rounded-lg border border-indigo-100 bg-white px-3 py-2">
                                <div class="text-[11px] text-indigo-600">Reembolso monetario</div>
                                <div class="font-bold text-gray-900">{{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($importeCancelacionCentavos - $reduccionCcCentavos)) }}</div>
                            </div>
                        </div>

                        @if($abonosReembolsoUi)
                            <div class="overflow-hidden rounded-lg border border-indigo-100 bg-white">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            <th class="px-4 py-2">Abono CxC</th>
                                            <th class="px-4 py-2 text-right">Disponible</th>
                                            <th class="px-4 py-2 text-right">A reembolsar</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach($abonosReembolsoUi as $abono)
                                            @php $aReembolsar = $asignacionAbonos[$abono['id']] ?? 0; @endphp
                                            <tr>
                                                <td class="px-4 py-3 font-semibold text-gray-900">
                                                    {{ $abono['metodo'] }}
                                                    <span class="text-xs font-normal text-gray-400">(abono CxC)</span>
                                                </td>
                                                <td class="px-4 py-3 text-right text-gray-700">
                                                    {{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($abono['disponible_centavos'])) }}
                                                </td>
                                                <td class="px-4 py-3 text-right font-bold text-gray-900">
                                                    {{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($aReembolsar)) }}
                                                </td>
                                            </tr>

                                            @if(in_array($abono['metodo'], ['TARJETA', 'TRANSFERENCIA'], true))
                                                <tr class="bg-gray-50">
                                                    <td colspan="3" class="px-4 py-3">
                                                        <label class="block text-xs font-semibold text-gray-700">
                                                            Referencia de devolución {{ $abono['metodo'] }} (abono CxC)
                                                        </label>
                                                        <input
                                                            type="text"
                                                            name="referencias_reembolso_cxc[{{ $abono['id'] }}]"
                                                            value="{{ old('referencias_reembolso_cxc.'.$abono['id']) }}"
                                                            maxlength="100"
                                                            autocomplete="off"
                                                            placeholder="Folio o autorización de la devolución"
                                                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                                                        >
                                                        <p class="mt-1 text-[11px] text-gray-500">
                                                            No captures número completo de tarjeta, CVV ni datos sensibles.
                                                        </p>
                                                    </td>
                                                </tr>
                                            @endif
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @if($reduccionCcCentavos >= $importeCancelacionCentavos)
                            <p class="text-xs font-semibold text-indigo-900">
                                La deuda pendiente absorbe todo el importe revertido.
                                <strong>No se entregará dinero</strong> en esta cancelación.
                            </p>
                        @elseif($efectivoEnPlan)
                            <p class="text-xs text-indigo-900">
                                El componente en <strong>EFECTIVO</strong> saldrá físicamente de la caja abierta
                                y quedará registrado en el corte.
                            </p>
                        @endif
                    </div>
                @endif

                @if(! $creditoPostventa)
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 space-y-3">
                        <div>
                            <h3 class="text-sm font-bold text-emerald-900">
                                Reembolso según el pago original
                            </h3>
                            <p class="mt-1 text-xs text-emerald-800">
                                Esta venta tiene pagos POS trazables. La forma de reembolso no puede modificarse:
                                cada importe se devolverá por el mismo medio con el que fue cobrado.
                            </p>
                        </div>

                        <div class="overflow-hidden rounded-lg border border-emerald-200 bg-white">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        <th class="px-4 py-2">Método</th>
                                        <th class="px-4 py-2 text-right">Cobrado</th>
                                        <th class="px-4 py-2 text-right">A reembolsar</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($pagosReembolsoUi as $pago)
                                        <tr>
                                            <td class="px-4 py-3 font-semibold text-gray-900">
                                                {{ $pago['metodo'] }}
                                            </td>
                                            <td class="px-4 py-3 text-right text-gray-700">
                                                {{ \App\Support\Money::formatear(
                                                    \App\Support\Money::aPrecio($pago['monto_centavos'])
                                                ) }}
                                            </td>
                                            <td class="px-4 py-3 text-right font-bold text-gray-900">
                                                {{ \App\Support\Money::formatear(
                                                    \App\Support\Money::aPrecio($pago['monto_centavos'])
                                                ) }}
                                            </td>
                                        </tr>

                                        @if(in_array($pago['metodo'], ['TARJETA', 'TRANSFERENCIA'], true))
                                            <tr class="bg-gray-50">
                                                <td colspan="3" class="px-4 py-3">
                                                    <label class="block text-xs font-semibold text-gray-700">
                                                        Referencia de devolución {{ $pago['metodo'] }}
                                                    </label>
                                                    <input
                                                        type="text"
                                                        name="referencias_reembolso[{{ $pago['id'] }}]"
                                                        value="{{ old('referencias_reembolso.'.$pago['id']) }}"
                                                        maxlength="100"
                                                        required
                                                        autocomplete="off"
                                                        placeholder="Folio o autorización de la devolución"
                                                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                                                    >
                                                    <p class="mt-1 text-[11px] text-gray-500">
                                                        No captures número completo de tarjeta, CVV ni datos sensibles.
                                                    </p>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if(collect($pagosReembolsoUi)->contains(fn ($p) => $p['metodo'] === 'EFECTIVO'))
                            <p class="text-xs text-emerald-900">
                                <strong>EFECTIVO:</strong> la parte correspondiente saldrá físicamente de la caja abierta
                                y quedará registrada en el corte.
                            </p>
                        @endif
                    </div>
                @else
                    <div
                        x-data="{ forma: @js(old('forma_reembolso', $sugerido)) }"
                        class="rounded-xl border border-amber-200 bg-amber-50 p-4 space-y-3"
                    >
                        <div>
                            <h3 class="text-sm font-bold text-amber-900">
                                Reembolso de venta histórica
                            </h3>
                            <p class="mt-1 text-xs text-amber-800">
                                Esta venta no tiene un desglose POS confiable de sus pagos originales.
                                La forma de reembolso debe indicarse manualmente y quedará auditada.
                            </p>
                        </div>

                        <div>
                            <x-input-label for="forma_reembolso" value="Forma de reembolso (obligatorio)" />

                            <select
                                name="forma_reembolso"
                                id="forma_reembolso"
                                x-model="forma"
                                required
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('forma_reembolso') border-rose-300 ring-rose-200 @enderror"
                            >
                                @foreach($formasReembolso as $forma)
                                    <option value="{{ $forma }}">
                                        {{ $forma }}
                                    </option>
                                @endforeach
                            </select>

                            <x-input-error :messages="$errors->get('forma_reembolso')" class="mt-2" />
                        </div>

                        <div
                            x-show="forma === 'TARJETA' || forma === 'TRANSFERENCIA'"
                            x-cloak
                        >
                            <x-input-label for="referencia_reembolso" value="Referencia de devolución" />

                            <input
                                id="referencia_reembolso"
                                name="referencia_reembolso"
                                type="text"
                                value="{{ old('referencia_reembolso') }}"
                                maxlength="100"
                                autocomplete="off"
                                x-bind:required="forma === 'TARJETA' || forma === 'TRANSFERENCIA'"
                                placeholder="Folio o autorización de la devolución"
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                            >

                            <p class="mt-1 text-[11px] text-gray-500">
                                No captures número completo de tarjeta, CVV ni datos sensibles.
                            </p>
                        </div>

                        <p
                            x-show="forma === 'EFECTIVO'"
                            class="text-xs text-amber-900"
                        >
                            El reembolso en efectivo requiere una sesión de caja abierta.
                        </p>
                    </div>
                @endif

                @if($errors->has('reembolso'))
                    <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                        {{ $errors->first('reembolso') }}
                    </div>
                @endif

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
