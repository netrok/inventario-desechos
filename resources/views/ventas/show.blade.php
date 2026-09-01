<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">{{ $venta->folio }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Comprobante de la venta. {{ $venta->created_at->format('d/m/Y H:i') }}
                </p>
            </div>

            <div class="flex items-center gap-2">
                <x-estado-badge :estado="$venta->estado" />
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('ventas.ticket', ['venta' => $venta, 'width' => 80]) }}"
                   target="_blank"
                   class="inline-flex items-center rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-black">
                    Imprimir ticket 80 mm
                </a>
                <a href="{{ route('ventas.ticket', ['venta' => $venta, 'width' => 58]) }}"
                   target="_blank"
                   class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50">
                    Ticket 58 mm
                </a>
                @can('ventas.devolver')
                    @if($venta->esElegibleParaDevolucion() && ! $venta->cuentaPorCobrar)
                        <a href="{{ route('ventas.devolver', $venta) }}"
                           class="inline-flex items-center rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-100">
                            Devolver equipos
                        </a>
                    @endif
                @endcan
                @can('ventas.cancelar')
                    @if($venta->esElegibleParaCancelacion() && ! $venta->cuentaPorCobrar)
                        <a href="{{ route('ventas.cancelar', $venta) }}"
                           class="inline-flex items-center rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-800 hover:bg-rose-100">
                            Cancelar venta
                        </a>
                    @endif
                @endcan
                <a href="{{ route('ventas.index') }}"
                   class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                    ← Volver a ventas
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 space-y-5">

            @if(session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            {{-- Cliente (snapshot histórico) --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <div class="text-xs text-gray-500 uppercase tracking-wide">Cliente</div>
                @if($venta->cliente_historico)
                    @php $ch = $venta->cliente_historico; @endphp
                    <div class="mt-2 grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div class="md:col-span-2">
                            <div class="text-sm font-semibold text-gray-900">
                                {{ $ch['nombre'] }}
                                @if($venta->cliente)
                                    @can('clientes.ver')
                                        <a href="{{ route('clientes.show', $venta->cliente) }}"
                                           class="ml-1 text-xs font-medium text-teal-700 hover:underline">{{ $ch['codigo'] }}</a>
                                    @endcan
                                @else
                                    <span class="text-xs text-gray-400">{{ $ch['codigo'] }}</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-500">
                                {{ $ch['tipo'] }}
                                @if($ch['rfc']) · RFC {{ $ch['rfc'] }} @endif
                            </div>
                        </div>
                        <div class="text-sm text-gray-700">
                            @if($ch['telefono']) <div>Tel: {{ $ch['telefono'] }}</div> @endif
                            @if($ch['email']) <div>Email: {{ $ch['email'] }}</div> @endif
                        </div>
                    </div>
                    @if(! $venta->cliente)
                        <p class="mt-2 text-xs text-gray-400">Datos históricos: el cliente ya no existe en el catálogo.</p>
                    @endif
                @else
                    <div class="mt-1 text-sm text-gray-500">
                        Cliente no registrado (venta histórica / anterior al módulo de clientes).
                    </div>
                @endif
            </div>

            {{-- Datos de la venta --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Folio</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $venta->folio }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Fecha</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $venta->created_at->format('d/m/Y H:i') }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Usuario</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $venta->user?->name ?? '—' }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Forma de pago</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $venta->forma_pago }}</div>
                </div>
            </div>

            @if($venta->cuentaPorCobrar)
                @php $cxc = $venta->cuentaPorCobrar; @endphp
                <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
                    <div class="flex items-center justify-between">
                        <div class="text-xs text-indigo-700 uppercase tracking-wide">Cuenta por cobrar (crédito)</div>
                        <span class="inline-flex rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">{{ $cxc->estado }}</span>
                    </div>
                    <div class="mt-2 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                        <div>
                            <div class="text-[11px] text-indigo-600">Folio CxC</div>
                            <div class="font-semibold text-gray-900">
                                @can('cxc.ver')
                                    <a href="{{ route('cxc.show', $cxc) }}" class="text-indigo-700 underline decoration-indigo-300 hover:text-indigo-900">
                                        {{ $cxc->folio }}
                                    </a>
                                @else
                                    {{ $cxc->folio }}
                                @endcan
                            </div>
                        </div>
                        <div>
                            <div class="text-[11px] text-indigo-600">Importe financiado</div>
                            <div class="font-semibold text-gray-900">{{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($cxc->importe_original_centavos)) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] text-indigo-600">Saldo actual</div>
                            <div class="font-semibold text-gray-900">{{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($cxc->saldo_centavos)) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] text-indigo-600">Fecha de vencimiento</div>
                            <div class="font-semibold text-gray-900">{{ $cxc->fecha_vencimiento?->format('Y-m-d') }}</div>
                        </div>
                        <div class="md:col-span-4">
                            <div class="text-[11px] text-indigo-600">Plazo aplicado</div>
                            <div class="font-semibold text-gray-900">{{ $cxc->dias_credito_aplicados }} día(s)</div>
                        </div>
                    </div>
                    <p class="mt-2 text-[11px] text-indigo-700">
                        Cobranza y abonos disponibles en el módulo de Cuentas por cobrar.
                        La postventa de esta venta permanece bloqueada hasta B15.5.
                    </p>
                </div>
            @endif

            {{-- Artículos vendidos --}}
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-3">Código</th>
                            <th class="px-5 py-3">Equipo</th>
                            <th class="px-5 py-3 text-right">Precio</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($venta->detalles as $detalle)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3">
                                    @can('items.ver')
                                        <a href="{{ route('items.show', $detalle->item) }}"
                                           class="font-semibold text-gray-900 hover:underline">
                                            {{ $detalle->item->codigo }}
                                        </a>
                                    @else
                                        <span class="font-semibold text-gray-900">{{ $detalle->item->codigo }}</span>
                                    @endcan
                                </td>
                                <td class="px-5 py-3 text-gray-700">
                                    {{ collect([$detalle->item->marca, $detalle->item->modelo])->filter()->implode(' · ') ?: $detalle->item->serie ?: 'Sin descripción' }}
                                    @if($detalle->item->categoria?->nombre)
                                        <span class="text-xs text-gray-400"> — {{ $detalle->item->categoria->nombre }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right font-semibold text-gray-900">{{ number_format((float) $detalle->precio, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="2" class="px-5 py-3 text-right text-sm font-semibold text-gray-700">Total</td>
                            <td class="px-5 py-3 text-right text-base font-bold text-gray-900">{{ number_format((float) $venta->total, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Pagos reales (B15.3): desglose READ-ONLY de lo efectivamente cobrado.
                 El crédito nunca es un PagoVenta; se muestra en el bloque CxC. --}}
            @php
                $pagosRealesCentavos = $venta->pagos->reduce(
                    fn (int $acc, $p) => $acc + \App\Support\Money::aCentavos((string) $p->monto_aplicado),
                    0
                );
                $creditoVentaCentavos = $venta->cuentaPorCobrar
                    ? (int) $venta->cuentaPorCobrar->importe_original_centavos
                    : 0;
            @endphp
            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <div class="text-xs text-gray-500 uppercase tracking-wide">Pagos reales</div>

                @if($venta->pagos->isNotEmpty())
                    @if($creditoVentaCentavos > 0)
                        <div class="mt-2 grid grid-cols-1 md:grid-cols-3 gap-2 text-sm">
                            <div class="text-gray-600">
                                Total <span class="float-right font-semibold text-gray-900">{{ \App\Support\Money::formatear((string) $venta->total) }}</span>
                            </div>
                            <div class="text-gray-600">
                                Pagos reales <span class="float-right font-semibold text-gray-900">{{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($pagosRealesCentavos)) }}</span>
                            </div>
                            <div class="text-indigo-700">
                                Crédito <span class="float-right font-semibold text-indigo-800">{{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($creditoVentaCentavos)) }}</span>
                            </div>
                        </div>
                    @endif

                    <div class="mt-3 divide-y divide-gray-100">
                        @foreach($venta->pagos as $pago)
                            <div class="flex flex-wrap items-center justify-between py-2 text-sm">
                                <div>
                                    <span class="font-medium text-gray-900">{{ $pago->metodo }}</span>
                                    @if($pago->referencia)
                                        <span class="ml-2 text-xs text-gray-400">Ref: {{ $pago->referencia }}</span>
                                    @endif
                                </div>
                                <div class="text-right">
                                    <span class="font-semibold text-gray-900">{{ \App\Support\Money::formatear((string) $pago->monto_aplicado) }}</span>
                                    @if($pago->metodo === 'EFECTIVO' && $pago->efectivo_recibido !== null && \App\Support\Money::aCentavos((string) $pago->efectivo_recibido) > 0)
                                        <div class="text-xs text-gray-400">
                                            Recibido {{ \App\Support\Money::formatear((string) $pago->efectivo_recibido) }}
                                            @if($pago->cambio_entregado !== null && \App\Support\Money::aCentavos((string) $pago->cambio_entregado) > 0)
                                                · Cambio {{ \App\Support\Money::formatear((string) $pago->cambio_entregado) }}
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @elseif($venta->cuentaPorCobrar)
                    <p class="mt-2 text-sm text-gray-500">
                        Sin pagos reales — venta 100% a crédito.
                    </p>
                @else
                    <p class="mt-2 text-sm text-gray-500">
                        Venta histórica / legacy sin desglose de pagos.
                    </p>
                @endif
            </div>

            @if($venta->notas)
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Notas</div>
                    <p class="mt-1 text-sm text-gray-700">{{ $venta->notas }}</p>
                </div>
            @endif

            {{-- Historial postventa --}}
            @if($venta->documentosPostventa->isNotEmpty())
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h3 class="text-sm font-semibold text-gray-900">Historial de postventa</h3>
                        <p class="mt-1 text-xs text-gray-500">Cancelaciones y devoluciones asociadas a esta venta.</p>
                    </div>

                    <div class="divide-y divide-gray-100">
                        @foreach($venta->documentosPostventa as $doc)
                            <div class="px-5 py-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('postventa.show', $doc) }}"
                                       class="font-semibold text-gray-900 hover:underline">{{ $doc->folio }}</a>
                                    <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                        {{ $doc->tipo }}
                                    </span>
                                    <span class="text-xs text-gray-400">{{ $doc->created_at->format('d/m/Y H:i') }}</span>
                                    <span class="text-xs text-gray-400">· {{ $doc->user?->name ?? '—' }}</span>
                                </div>

                                @foreach($doc->detalles as $detalle)
                                    <div class="mt-1 flex items-center justify-between text-sm">
                                        <span class="text-gray-700">{{ $detalle->item?->codigo ?? '—' }}</span>
                                        <span class="font-semibold text-gray-900">{{ number_format((float) $detalle->importe, 2) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>