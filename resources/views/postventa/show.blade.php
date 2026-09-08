<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">{{ $documento->folio }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    {{ $documento->tipo === 'CANCELACION' ? 'Cancelación de venta' : 'Devolución de equipos' }}
                    — {{ $documento->created_at->format('d/m/Y H:i') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <x-estado-badge :estado="$documento->venta->estado" />
                <a href="{{ route('postventa.print', $documento) }}"
                   target="_blank"
                   class="inline-flex items-center rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-black">
                    Imprimir comprobante
                </a>
                <a href="{{ route('ventas.show', $documento->venta) }}"
                   class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                    ← Ver venta
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

            @if(session('pendientesRevision') && auth()->user()->can('items.revisar_devolucion'))
                <div class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800 flex flex-wrap items-center justify-between gap-2">
                    <span>Los artículos devueltos quedaron pendientes de revisión.</span>
                    <a href="{{ route('items.index', ['estado' => 'DEVUELTO']) }}"
                       class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700">
                        Revisar artículos devueltos
                    </a>
                </div>
            @endif

            {{-- Datos del documento --}}
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Documento</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $documento->folio }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Tipo</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $documento->tipo }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Venta origen</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">
                        <a href="{{ route('ventas.show', $documento->venta) }}" class="hover:underline">{{ $documento->venta->folio }}</a>
                    </div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Fecha</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $documento->created_at->format('d/m/Y H:i') }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Usuario</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $documento->user?->name ?? '—' }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Forma de reembolso</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $documento->forma_reembolso ?? '—' }}</div>
                </div>
            </div>

            {{-- Motivo --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <div class="text-xs text-gray-500">Motivo</div>
                <p class="mt-1 text-sm text-gray-700">{{ $documento->motivo }}</p>
            </div>

            {{-- Items --}}
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-3">Código</th>
                            <th class="px-5 py-3">Equipo</th>
                            <th class="px-5 py-3 text-right">Importe</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($documento->detalles as $detalle)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 font-semibold text-gray-900">{{ $detalle->item?->codigo ?? '—' }}</td>
                                <td class="px-5 py-3 text-gray-700">
                                    {{ collect([$detalle->item?->marca, $detalle->item?->modelo])->filter()->implode(' · ') ?: $detalle->item?->serie ?: 'Sin descripción' }}
                                </td>
                                <td class="px-5 py-3 text-right font-semibold text-gray-900">{{ number_format((float) $detalle->importe, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="2" class="px-5 py-3 text-right text-sm font-semibold text-gray-700">Total</td>
                            <td class="px-5 py-3 text-right text-base font-bold text-gray-900">{{ number_format((float) $documento->total, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Desglose económico (B15.5): deuda + reembolsos monetarios --}}
            @php
                $reembolsoMonetarioCentavos = $documento->reembolsos->sum(
                    fn ($r) => \App\Support\Money::aCentavos((string) $r->monto)
                );
                $deudaCentavos = $documento->movimientoCxCDeuda
                    ? (int) $documento->movimientoCxCDeuda->monto_centavos
                    : 0;
            @endphp
            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <div class="text-xs text-gray-500 uppercase tracking-wide">Desglose económico</div>

                <div class="mt-2 grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                    <div>
                        <div class="text-[11px] text-gray-500">Importe del documento</div>
                        <div class="font-bold text-gray-900">{{ number_format((float) $documento->total, 2) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] text-indigo-600">Reducción de deuda CxC</div>
                        <div class="font-bold text-gray-900">{{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($deudaCentavos)) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] text-gray-500">Reembolso monetario</div>
                        <div class="font-bold text-gray-900">{{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($reembolsoMonetarioCentavos)) }}</div>
                    </div>
                </div>

                @if($documento->movimientoCxCDeuda)
                    <div class="mt-3 border-t border-gray-100 pt-2 text-sm">
                        <span class="text-xs text-indigo-600">Aplicado a la Cuenta por Cobrar</span>
                        <span class="mx-1 font-semibold text-gray-900">
                            {{ $documento->movimientoCxCDeuda->cuentaPorCobrar?->folio ?? 'CxC' }}
                        </span>
                        <span class="text-xs text-gray-400">
                            — saldo ${{
                                $documento->movimientoCxCDeuda->cuentaPorCobrar
                                    ? number_format($documento->movimientoCxCDeuda->cuentaPorCobrar->saldo_centavos / 100, 2)
                                    : '—'
                            }}
                        </span>
                    </div>
                @endif

                @if($documento->reembolsos->isNotEmpty())
                    <div class="mt-3 divide-y divide-gray-100">
                        @foreach($documento->reembolsos as $reembolso)
                            <div class="flex flex-wrap items-center justify-between py-2 text-sm">
                                <div>
                                    <span class="font-medium text-gray-900">{{ $reembolso->metodo }}</span>
                                    @if($reembolso->esCxC())
                                        <span class="ml-2 inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">Abono CxC</span>
                                    @elseif($reembolso->pagoVenta)
                                        <span class="ml-2 inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Pago checkout</span>
                                    @else
                                        <span class="ml-2 inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Manual legacy</span>
                                    @endif
                                    @if($reembolso->referencia)
                                        <span class="ml-2 text-xs text-gray-400">Ref: {{ $reembolso->referencia }}</span>
                                    @endif
                                </div>
                                <div class="font-semibold text-gray-900">{{ number_format((float) $reembolso->monto, 2) }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Venta original --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <div class="text-xs text-gray-500">Venta original</div>
                <div class="mt-1 text-sm text-gray-700">
                    {{ $documento->venta->folio }} ·
                    {{ $documento->venta->created_at->format('d/m/Y H:i') }} ·
                    {{ $documento->venta->user?->name ?? '—' }} ·
                    Total {{ number_format((float) $documento->venta->total, 2) }} ·
                    <x-estado-badge :estado="$documento->venta->estado" />
                </div>
                @if($documento->venta->cliente_historico)
                    @php $ch = $documento->venta->cliente_historico; @endphp
                    <div class="mt-2 border-t border-gray-100 pt-2 text-sm text-gray-700">
                        <span class="text-xs text-gray-500">Cliente: </span>{{ $ch['nombre'] }}
                        <span class="text-xs text-gray-400">({{ $ch['codigo'] }})</span>
                    </div>
                @else
                    <div class="mt-2 border-t border-gray-100 pt-2 text-sm text-gray-400">
                        Cliente no registrado (venta histórica)
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>