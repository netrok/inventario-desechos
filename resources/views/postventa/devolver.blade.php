<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Devolver equipos — {{ $venta->folio }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Registra la devolución física (parcial o total) de equipos vendidos. El importe se calcula en el servidor
                    con el precio histórico de cada detalle.
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

            @if($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <script>
                window.devolucionPostventa = function () {
                    return {
                        totalCentavos: 0,

                        pagos: @json($pagosReembolsoUi),

                        abonos: @json($abonosReembolsoUi),

                        saldoCentavos: {{ $venta->cuentaPorCobrar?->saldo_centavos ?? 0 }},

                        updateTotal() {
                            const checks = [
                                ...this.$el.querySelectorAll(
                                    'input[name="detalles[]"]:checked'
                                )
                            ];

                            this.totalCentavos = checks.reduce(
                                (acc, cb) =>
                                    acc + Number(cb.dataset.centavos || 0),
                                0
                            );
                        },

                        money(centavos) {
                            return (Number(centavos) / 100).toLocaleString(
                                'es-MX',
                                {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                }
                            );
                        },

                        reduccionDeuda() {
                            return Math.min(
                                this.saldoCentavos,
                                this.totalCentavos
                            );
                        },

                        reembolsoMonetario() {
                            return Math.max(
                                0,
                                this.totalCentavos - this.reduccionDeuda()
                            );
                        },

                        abonoReembolso(abonoId) {
                            let restante = this.reembolsoMonetario();

                            for (const abono of this.abonos) {
                                if (restante <= 0) {
                                    break;
                                }

                                const disponible = abono.disponible_centavos;

                                if (disponible <= 0) {
                                    continue;
                                }

                                const tomar = Math.min(disponible, restante);

                                if (Number(abono.id) === Number(abonoId)) {
                                    return tomar;
                                }

                                restante -= tomar;
                            }

                            return 0;
                        },

                        efectivoEnReembolso() {
                            let restante = this.reembolsoMonetario();

                            for (const abono of this.abonos) {
                                if (restante <= 0) {
                                    break;
                                }

                                const disponible = abono.disponible_centavos;

                                if (disponible <= 0) {
                                    continue;
                                }

                                const tomar = Math.min(disponible, restante);

                                if (abono.metodo === 'EFECTIVO') {
                                    return true;
                                }

                                restante -= tomar;
                            }

                            return false;
                        },

                        reembolsoPago(pagoId) {
                            if (
                                this.totalCentavos <= 0 ||
                                this.pagos.length === 0
                            ) {
                                return 0;
                            }

                            const totalOriginal = this.pagos.reduce(
                                (acc, p) =>
                                    acc + BigInt(p.monto_centavos),
                                0n
                            );

                            const totalAnterior = this.pagos.reduce(
                                (acc, p) =>
                                    acc + BigInt(
                                        p.ya_reembolsado_centavos
                                    ),
                                0n
                            );

                            const objetivo =
                                totalAnterior +
                                BigInt(this.totalCentavos);

                            if (objetivo > totalOriginal) {
                                return 0;
                            }

                            let sumaBases = 0n;

                            const filas = this.pagos.map((p) => {
                                const numerador =
                                    objetivo *
                                    BigInt(p.monto_centavos);

                                const base =
                                    numerador / totalOriginal;

                                const resto =
                                    numerador % totalOriginal;

                                sumaBases += base;

                                return {
                                    id: Number(p.id),
                                    orden: Number(p.orden),
                                    base: base,
                                    resto: resto,
                                    anterior: BigInt(
                                        p.ya_reembolsado_centavos
                                    )
                                };
                            });

                            const pendientes =
                                Number(objetivo - sumaBases);

                            filas.sort((a, b) => {
                                if (a.resto !== b.resto) {
                                    return a.resto > b.resto
                                        ? -1
                                        : 1;
                                }

                                if (a.orden !== b.orden) {
                                    return a.orden - b.orden;
                                }

                                return a.id - b.id;
                            });

                            for (
                                let i = 0;
                                i < pendientes;
                                i++
                            ) {
                                filas[i].base += 1n;
                            }

                            const fila = filas.find(
                                (f) =>
                                    f.id === Number(pagoId)
                            );

                            if (!fila) {
                                return 0;
                            }

                            const nuevo =
                                fila.base - fila.anterior;

                            return nuevo > 0n
                                ? Number(nuevo)
                                : 0;
                        }
                    };
                };
            </script>

            <form
                method="POST"
                action="{{ route('ventas.devolver.store', $venta) }}"
                class="space-y-5"
                x-data="devolucionPostventa()"
                x-init="$nextTick(() => updateTotal())"
            >
                @csrf

                {{-- Selección de equipos devolubles --}}
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-3 w-10"></th>
                                <th class="px-5 py-3">Código</th>
                                <th class="px-5 py-3">Equipo</th>
                                <th class="px-5 py-3 text-right">Importe (histórico)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @php
                                $devolubles = $venta->detalles->filter(
                                    fn ($d) => $d->documentoPostventaDetalle === null && $d->item?->estado === 'VENDIDO'
                                );
                            @endphp

                            @forelse($devolubles as $detalle)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3">
                                        <input type="checkbox"
                                               name="detalles[]"
                                               value="{{ $detalle->id }}"
                                               data-centavos="{{ \App\Support\Money::aCentavos($detalle->precio) }}"
                                               x-on:change="
                                                   totalCentavos += $event.target.checked
                                                       ? Number($event.target.dataset.centavos)
                                                       : -Number($event.target.dataset.centavos)
                                               "
                                               class="rounded border-gray-300">
                                    </td>
                                    <td class="px-5 py-3 font-semibold text-gray-900">{{ $detalle->item->codigo }}</td>
                                    <td class="px-5 py-3 text-gray-700">
                                        {{ collect([$detalle->item->marca, $detalle->item->modelo])->filter()->implode(' · ') ?: $detalle->item->serie ?: 'Sin descripción' }}
                                    </td>
                                    <td class="px-5 py-3 text-right font-semibold text-gray-900">{{ number_format((float) $detalle->precio, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-8 text-center text-sm text-gray-500">
                                        No hay equipos devolubles en esta venta.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="3" class="px-5 py-3 text-right text-sm font-semibold text-gray-700">Total a devolver</td>
                                <td class="px-5 py-3 text-right text-base font-bold text-gray-900">
                                    <span
                                        x-text="money(totalCentavos)"
                                        x-bind:class="totalCentavos > 0 ? 'text-gray-900' : 'text-gray-400'"
                                    >0.00</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                    <x-input-error :messages="$errors->get('detalles')" class="px-5 py-2" />
                </div>

                {{-- Motivo + forma de reembolso --}}
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
                    <div>
                        <x-input-label for="motivo" value="Motivo de la devolución (obligatorio)" />
                        <textarea
                            id="motivo"
                            name="motivo"
                            rows="3"
                            required
                            minlength="3"
                            maxlength="2000"
                            placeholder="Indica el motivo de la devolución…"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('motivo') border-rose-300 ring-rose-200 @enderror"
                        >{{ old('motivo') }}</textarea>
                        <x-input-error :messages="$errors->get('motivo')" class="mt-2" />
                    </div>

                    @if($creditoPostventa)
                        <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 space-y-3">
                            <div>
                                <h3 class="text-sm font-bold text-indigo-900">
                                    Aplicación deuda-primero (crédito)
                                </h3>

                                <p class="mt-1 text-xs text-indigo-800">
                                    El importe de los equipos seleccionados se aplica PRIMERO a reducir la
                                    deuda pendiente de la Cuenta por Cobrar y <strong>solo el sobrante</strong>
                                    se entrega como reembolso monetario (por el mismo medio con el que se cobró).
                                </p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                                <div class="rounded-lg border border-indigo-100 bg-white px-3 py-2">
                                    <div class="text-[11px] text-indigo-600">A devolver</div>
                                    <div class="font-bold text-gray-900" x-text="money(totalCentavos)">0.00</div>
                                </div>
                                <div class="rounded-lg border border-indigo-100 bg-white px-3 py-2">
                                    <div class="text-[11px] text-indigo-600">Reembolso monetario</div>
                                    <div class="font-bold text-gray-900" x-text="money(reembolsoMonetario())">0.00</div>
                                </div>
                            </div>

                            @if($abonosReembolsoUi)
                                <div class="overflow-hidden rounded-lg border border-indigo-100 bg-white">
                                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50">
                                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                <th class="px-4 py-2">Abono CxC</th>
                                                <th class="px-4 py-2 text-right">Disponible</th>
                                                <th class="px-4 py-2 text-right">Esta devolución</th>
                                            </tr>
                                        </thead>

                                        <tbody class="divide-y divide-gray-100">
                                            @foreach($abonosReembolsoUi as $abono)
                                                <tr>
                                                    <td class="px-4 py-3 font-semibold text-gray-900">
                                                        {{ $abono['metodo'] }}
                                                        <span class="text-xs font-normal text-gray-400">(abono CxC)</span>
                                                    </td>

                                                    <td class="px-4 py-3 text-right text-gray-700">
                                                        {{ \App\Support\Money::formatear(
                                                            \App\Support\Money::aPrecio($abono['disponible_centavos'])
                                                        ) }}
                                                    </td>

                                                    <td
                                                        class="px-4 py-3 text-right font-bold text-gray-900"
                                                        x-text="money(abonoReembolso({{ $abono['id'] }}))"
                                                    >
                                                        0.00
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
                                                                x-bind:required="abonoReembolso({{ $abono['id'] }}) > 0"
                                                                x-bind:disabled="abonoReembolso({{ $abono['id'] }}) === 0"
                                                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100"
                                                            >

                                                            <p class="mt-1 text-[11px] text-gray-500">
                                                                Solo es necesaria cuando esta devolución tiene importe en ese método.
                                                            </p>
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif

                            <p
                                class="text-xs text-indigo-900"
                                x-show="reembolsoMonetario() === 0"
                                x-cloak
                            >
                                La deuda pendiente absorbe todo el importe de esta devolución.
                                <strong>No se entregará dinero</strong> en esta operación.
                            </p>

                            <p
                                class="text-xs text-indigo-900"
                                x-show="reembolsoMonetario() > 0 && ! efectivoEnReembolso()"
                                x-cloak
                            >
                                Los abonos que absorben el reembolso no incluyen efectivo: no se tocará la caja física.
                            </p>

                            <p
                                class="text-xs text-indigo-900"
                                x-show="efectivoEnReembolso()"
                                x-cloak
                            >
                                El componente en <strong>EFECTIVO</strong> saldrá de la caja abierta y quedará
                                registrado automáticamente en el corte.
                            </p>
                        </div>
                    @endif

                    @if(! $creditoPostventa)
                    @if($reembolsoAutomatico)
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 space-y-3">
                            <div>
                                <h3 class="text-sm font-bold text-emerald-900">
                                    Reembolso según el pago original
                                </h3>

                                <p class="mt-1 text-xs text-emerald-800">
                                    Selecciona los equipos. El sistema prorratea el importe usando la
                                    composición original de pagos y considera devoluciones anteriores.
                                </p>
                            </div>

                            <div class="overflow-hidden rounded-lg border border-emerald-200 bg-white">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            <th class="px-4 py-2">Método original</th>
                                            <th class="px-4 py-2 text-right">Cobrado</th>
                                            <th class="px-4 py-2 text-right">Esta devolución</th>
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

                                                <td
                                                    class="px-4 py-3 text-right font-bold text-gray-900"
                                                    x-text="money(reembolsoPago({{ $pago['id'] }}))"
                                                >
                                                    0.00
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
                                                            autocomplete="off"
                                                            placeholder="Folio o autorización de la devolución"
                                                            x-bind:required="reembolsoPago({{ $pago['id'] }}) > 0"
                                                            x-bind:disabled="reembolsoPago({{ $pago['id'] }}) === 0"
                                                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100"
                                                        >

                                                        <p class="mt-1 text-[11px] text-gray-500">
                                                            Solo es necesaria cuando esta devolución tiene importe en ese método.
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
                                    La parte en <strong>EFECTIVO</strong> saldrá de la caja abierta y quedará
                                    registrada automáticamente en el corte.
                                </p>
                            @endif
                        </div>
                    @else
                        <div
                            x-data="{ formaLegacy: @js(old('forma_reembolso')) }"
                            class="rounded-xl border border-amber-200 bg-amber-50 p-4 space-y-3"
                        >
                            <div>
                                <h3 class="text-sm font-bold text-amber-900">
                                    Reembolso de venta histórica
                                </h3>

                                <p class="mt-1 text-xs text-amber-800">
                                    No existe un desglose confiable de los pagos originales.
                                    Debes indicar manualmente cómo se realizó el reembolso.
                                </p>
                            </div>

                            <div>
                                <x-input-label for="forma_reembolso" value="Forma de reembolso" />

                                <select
                                    id="forma_reembolso"
                                    name="forma_reembolso"
                                    x-model="formaLegacy"
                                    required
                                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                                >
                                    <option value="" disabled>Selecciona una opción</option>

                                    @foreach($formasReembolso as $forma)
                                        <option value="{{ $forma }}">
                                            {{ $forma }}
                                        </option>
                                    @endforeach
                                </select>

                                <x-input-error :messages="$errors->get('forma_reembolso')" class="mt-2" />
                            </div>

                            <div
                                x-show="formaLegacy === 'TARJETA' || formaLegacy === 'TRANSFERENCIA'"
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
                                    x-bind:required="formaLegacy === 'TARJETA' || formaLegacy === 'TRANSFERENCIA'"
                                    placeholder="Folio o autorización de la devolución"
                                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                                >
                            </div>
                        </div>
                    @endif
                    @endif

                    @if($errors->has('reembolso'))
                        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                            {{ $errors->first('reembolso') }}
                        </div>
                    @endif

                    <div class="flex gap-2">
                        <a href="{{ route('ventas.show', $venta) }}"
                           class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                            Volver
                        </a>
                        <button type="submit"
                                class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                            Confirmar devolución
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
