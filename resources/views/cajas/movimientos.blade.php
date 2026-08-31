<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">
                    Sesión {{ $sesion->folio }} · {{ $sesion->caja->nombre }}
                </h2>
                <p class="mt-1 text-sm text-gray-600">
                    Movimientos del efectivo físico. Los pagos electrónicos no afectan este corte.
                </p>
            </div>

            <div class="flex items-center gap-2">
                @if(! $sesion->estaAbierta())
                    @can('cajas.ver')
                        <a href="{{ route('cajas.corte', $sesion) }}"
                           class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                            Ver corte
                        </a>
                        <a href="{{ route('cajas.corte.imprimir', $sesion) }}"
                           target="_blank"
                           rel="noopener"
                           class="inline-flex items-center rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-black">
                            Imprimir
                        </a>
                        <a href="{{ route('cajas.corte.pdf', $sesion) }}"
                           class="inline-flex items-center rounded-lg bg-rose-700 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-800">
                            PDF
                        </a>
                        <a href="{{ route('cajas.corte.xlsx', $sesion) }}"
                           class="inline-flex items-center rounded-lg border border-emerald-700 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">
                            XLSX
                        </a>
                    @endcan
                @endif
                @can('cajas.cerrar')
                    @if($sesion->estaAbierta() && $sesion->user_id_apertura === auth()->id())
                        <a href="{{ route('cajas.cerrar', $sesion) }}"
                           class="inline-flex items-center rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                            Realizar corte
                        </a>
                    @endif
                @endcan
                <a href="{{ route('cajas.index') }}"
                   class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                    ← Caja
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8 space-y-5">

            @if(session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            {{-- Resumen de la sesión --}}
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                    <div class="rounded-2xl border border-gray-200 bg-white p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Estado</p>
                        <p class="mt-1 text-lg font-bold {{ $sesion->estaAbierta() ? 'text-emerald-600' : 'text-gray-800' }}">{{ $sesion->estado }}</p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Apertura</p>
                        <p class="mt-1 text-lg font-bold text-gray-800">{{ $sesion->opened_at?->format('d/m/Y H:i') }}</p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Operador</p>
                        <p class="mt-1 text-lg font-bold text-gray-800">{{ $sesion->usuarioApertura?->name }}</p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Fondo inicial</p>
                        <p class="mt-1 text-lg font-bold text-gray-800">{{ number_format((float) $sesion->fondo_inicial, 2) }}</p>
                    </div>
                    @if(! $sesion->estaAbierta())
                        <div class="rounded-2xl border border-gray-200 bg-white p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Cierre</p>
                            <p class="mt-1 text-lg font-bold text-gray-800">{{ $sesion->closed_at?->format('d/m/Y H:i') }}</p>
                        </div>
                    @endif
                </div>

            @if(! $sesion->estaAbierta())
                {{-- Resultado del corte --}}
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h3 class="text-sm font-semibold text-gray-900">Resultado del corte</h3>
                    </div>
                    <div class="px-5 py-4">
                        <div class="flex flex-wrap items-center gap-8">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Esperado</p>
                                <p class="mt-1 text-xl font-bold text-gray-800">{{ $sesion->efectivo_esperado !== null ? number_format((float) $sesion->efectivo_esperado, 2) : '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Contado (arqueo)</p>
                                <p class="mt-1 text-xl font-bold text-gray-800">{{ $sesion->efectivo_contado !== null ? number_format((float) $sesion->efectivo_contado, 2) : '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Diferencia</p>
                                <p class="mt-1 text-xl font-bold {{ $sesion->diferencia === null ? 'text-gray-400' : ($sesion->diferencia == 0 ? 'text-emerald-600' : 'text-rose-600') }}">
                                    {{ $sesion->diferencia !== null ? number_format((float) $sesion->diferencia, 2) : '—' }}
                                </p>
                            </div>
                        </div>

                        @if($sesion->observaciones_cierre)
                            <p class="mt-3 text-sm text-gray-600">
                                <strong>Observaciones de cierre:</strong> {{ $sesion->observaciones_cierre }}
                            </p>
                        @endif

                        @foreach($sesion->arqueos as $arqueo)
                            @if($arqueo->denominaciones->isNotEmpty())
                                <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-2">
                                    @foreach($arqueo->denominaciones as $den)
                                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs">
                                            <span class="font-semibold text-gray-900">
                                                {{ $den->denominacion % 100 == 0 ? '$'.number_format($den->denominacion, 0) : '$'.$den->denominacion }}
                                            </span>
                                            <span class="text-gray-500">× {{ $den->cantidad }}</span>
                                            <span class="block font-semibold text-gray-700">{{ number_format((float) $den->subtotal, 2) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @else
                {{-- Entrada / retiro / ajuste manual (solo con permiso de escritura) --}}
                @php
                    $puedeEscribir = auth()->user()->can('cajas.entrada')
                        || auth()->user()->can('cajas.retiro')
                        || auth()->user()->can('cajas.ajustar');
                @endphp
                @if($puedeEscribir)
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                        Operación administrativa: se registra como movimiento inmutable y auditable.
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                        @can('cajas.entrada')
                            <form method="POST" action="{{ route('cajas.entrada', $sesion) }}"
                                  class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-3">
                                @csrf
                                <h3 class="text-sm font-semibold text-gray-900">Entrada manual</h3>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <input type="number" name="monto" step="0.01" min="0.01" required placeholder="Monto"
                                               class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                                    </div>
                                    <div>
                                        <input type="text" name="referencia" maxlength="100" placeholder="Referencia (opcional)"
                                               class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                                    </div>
                                </div>
                                <input type="text" name="concepto" required maxlength="255" placeholder="Concepto (ej. cambio por billete roto)"
                                       class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                                <button type="submit"
                                        class="w-full rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                                    Registrar entrada
                                </button>
                            </form>
                        @endcan

                        @can('cajas.retiro')
                            <form method="POST" action="{{ route('cajas.retiro', $sesion) }}"
                                  class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-3">
                                @csrf
                                <h3 class="text-sm font-semibold text-gray-900">Retiro manual</h3>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <input type="number" name="monto" step="0.01" min="0.01" required placeholder="Monto"
                                               class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                                    </div>
                                    <div>
                                        <input type="text" name="referencia" maxlength="100" placeholder="Referencia (opcional)"
                                               class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                                    </div>
                                </div>
                                <input type="text" name="motivo" required maxlength="255" placeholder="Motivo (ej. gasto menor)"
                                       class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                                <button type="submit"
                                        class="w-full rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                                    Registrar retiro
                                </button>
                            </form>
                        @endcan

                        @can('cajas.ajustar')
                            <form method="POST" action="{{ route('cajas.ajuste', $sesion) }}"
                                  class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm space-y-3">
                                @csrf
                                <h3 class="text-sm font-semibold text-amber-800">Ajuste administrativo</h3>
                                <div>
                                    <select name="direccion" required
                                            class="w-full rounded-lg border-gray-300 text-sm focus:border-amber-600 focus:ring-amber-600">
                                        <option value="ENTRADA">ENTRADA (+)</option>
                                        <option value="SALIDA">SALIDA (−)</option>
                                    </select>
                                </div>
                                <input type="number" name="monto" step="0.01" min="0.01" required placeholder="Monto"
                                       class="w-full rounded-lg border-gray-300 text-sm focus:border-amber-600 focus:ring-amber-600">
                                <input type="text" name="referencia" maxlength="100" placeholder="Referencia (opcional)"
                                       class="w-full rounded-lg border-gray-300 text-sm focus:border-amber-600 focus:ring-amber-600">
                                <input type="text" name="motivo" required maxlength="255" placeholder="Motivo (obligatorio)"
                                       class="w-full rounded-lg border-gray-300 text-sm focus:border-amber-600 focus:ring-amber-600">
                                <button type="submit"
                                        class="w-full rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                                    Registrar ajuste
                                </button>
                            </form>
                        @endcan
                    </div>
                @endif
            @endif

            {{-- Movimientos de efectivo --}}
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-4 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900">Movimientos de efectivo ({{ $sesion->movimientos->count() }})</h3>
                    @if(! $sesion->estaAbierta())
                        <div class="text-xs text-gray-500 space-x-4">
                            <span class="text-emerald-700">Entradas: {{ number_format($entradas / 100, 2) }}</span>
                            <span class="text-rose-700">Salidas: {{ number_format($salidas / 100, 2) }}</span>
                        </div>
                    @endif
                </div>

                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-3">Fecha</th>
                            <th class="px-5 py-3">Tipo</th>
                            <th class="px-5 py-3">Concepto</th>
                            <th class="px-5 py-3">Referencia</th>
                            <th class="px-5 py-3">Operador</th>
                            <th class="px-5 py-3 text-right">Monto</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($sesion->movimientos as $mov)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 text-gray-700 whitespace-nowrap">{{ $mov->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold
                                        {{ $mov->esEntrada() ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                        {{ $mov->tipo }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-gray-800">{{ $mov->concepto }}</td>
                                <td class="px-5 py-3 text-gray-500">{{ $mov->referencia ?? '—' }}</td>
                                <td class="px-5 py-3 text-gray-700">{{ $mov->user?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-right font-semibold {{ $mov->esEntrada() ? 'text-emerald-700' : 'text-rose-700' }}">
                                    {{ $mov->esEntrada() ? '+' : '−' }} {{ number_format((float) $mov->monto, 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500">
                                    Esta sesión aún no registra movimientos de efectivo.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagos de la sesión --}}
            @if($sesion->pagos->isNotEmpty())
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h3 class="text-sm font-semibold text-gray-900">Pagos de ventas ({{ $sesion->pagos->count() }})</h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-3">Venta</th>
                                <th class="px-5 py-3">Método</th>
                                <th class="px-5 py-3 text-right">Aplicado</th>
                                <th class="px-5 py-3 text-right">Recibido</th>
                                <th class="px-5 py-3 text-right">Cambio</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($sesion->pagos as $pago)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('ventas.show', $pago->venta) }}" class="font-semibold text-gray-900 hover:underline">
                                            {{ $pago->venta?->folio ?? '—' }}
                                        </a>
                                        <span class="text-xs text-gray-400">{{ $pago->origen }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-gray-700">{{ $pago->metodo }}</td>
                                    <td class="px-5 py-3 text-right text-gray-900">{{ number_format((float) $pago->monto_aplicado, 2) }}</td>
                                    <td class="px-5 py-3 text-right text-gray-700">{{ $pago->efectivo_recibido !== null ? number_format((float) $pago->efectivo_recibido, 2) : '—' }}</td>
                                    <td class="px-5 py-3 text-right text-rose-700">{{ $pago->cambio_entregado !== null ? number_format((float) $pago->cambio_entregado, 2) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>