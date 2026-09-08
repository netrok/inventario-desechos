<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Caja y cortes</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Sesiones de caja, movimientos de efectivo, arqueos y cortes.
                    Para vender necesitas una sesión de caja abierta.
                </p>
            </div>

            @can('cajas.abrir')
                @if($abierta)
                    <a href="{{ route('cajas.movimientos', $abierta) }}"
                       class="inline-flex items-center rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">
                        Sesión {{ $abierta->folio }} abierta · Ver detalle
                    </a>
                @else
                    <a href="{{ route('cajas.abrir') }}"
                       class="inline-flex items-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                        Abrir caja
                    </a>
                @endif
            @endcan
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8 space-y-5">

            @if(session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('info'))
                <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                    {{ session('info') }}
                </div>
            @endif

            @if($abierta)
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-emerald-900">
                                Tienes la sesión <strong>{{ $abierta->folio }}</strong> abierta en {{ $abierta->caja->nombre }} ({{ $abierta->caja->codigo }}).
                            </p>
                            <p class="mt-1 text-xs text-emerald-700">
                                Apertura: {{ $abierta->opened_at?->format('d/m/Y H:i') }} · Fondo inicial:
                                {{ number_format((float) $abierta->fondo_inicial, 2) }}
                            </p>
                        </div>
                        @can('cajas.cerrar')
                            <a href="{{ route('cajas.cerrar', $abierta) }}"
                               class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                                Realizar corte
                            </a>
                        @endcan
                    </div>
                </div>
            @else
                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800">
                    <strong>No tienes una sesión de caja abierta.</strong>
                    @can('cajas.abrir')
                        Debes abrir una caja para registrar ventas.
                    @else
                        Tu rol no puede abrir caja; solicita que un operador la abra.
                    @endcan
                </div>
            @endif

            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-4 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900">
                        @if($puedeVerTodas)
                            Todas las sesiones
                        @else
                            Mis sesiones
                        @endif
                    </h3>
                    <span class="text-xs text-gray-500">{{ $sesiones->total() }} registro(s)</span>
                </div>

                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-3">Folio</th>
                            <th class="px-5 py-3">Caja</th>
                            <th class="px-5 py-3">Estado</th>
                            @if($puedeVerTodas)
                                <th class="px-5 py-3">Operador</th>
                            @endif
                            <th class="px-5 py-3">Apertura</th>
                            <th class="px-5 py-3">Cierre</th>
<th class="px-5 py-3 text-right">Fondo</th>
<th class="px-5 py-3 text-right">Esperado</th>
<th class="px-5 py-3 text-right">Diferencia</th>
<th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($sesiones as $sesion)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 font-semibold text-gray-900">{{ $sesion->folio }}</td>
                                <td class="px-5 py-3 text-gray-700">
                                    {{ $sesion->caja?->nombre ?? '—' }}
                                    <span class="text-xs text-gray-400">{{ $sesion->caja?->codigo }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold
                                        {{ $sesion->estaAbierta() ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">
                                        {{ $sesion->estado }}
                                    </span>
                                </td>
                                @if($puedeVerTodas)
                                    <td class="px-5 py-3 text-gray-700">{{ $sesion->usuarioApertura?->name }}</td>
                                @endif
                                <td class="px-5 py-3 text-gray-700">{{ $sesion->opened_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-3 text-gray-700">{{ $sesion->closed_at?->format('d/m/Y H:i') ?? '—' }}</td>
<td class="px-5 py-3 text-right text-gray-900">{{ number_format((float) $sesion->fondo_inicial, 2) }}</td>
<td class="px-5 py-3 text-right text-gray-700">{{ $sesion->efectivo_esperado !== null ? number_format((float) $sesion->efectivo_esperado, 2) : '—' }}</td>
<td class="px-5 py-3 text-right font-semibold {{ $sesion->diferencia === null ? 'text-gray-400' : ($sesion->diferencia == 0 ? 'text-emerald-700' : 'text-rose-700') }}">
    {{ $sesion->diferencia !== null ? number_format((float) $sesion->diferencia, 2) : '—' }}
</td>
<td class="px-5 py-3 text-right whitespace-nowrap">
                                    @if($sesion->estaAbierta())
                                        @can('cajas.movimientos')
                                            <a href="{{ route('cajas.movimientos', $sesion) }}"
                                               class="text-sm font-semibold text-gray-900 hover:underline">
                                                Ver movimientos
                                            </a>
                                        @endcan

                                        @can('cajas.cerrar')
                                            @if($sesion->user_id_apertura === auth()->id())
                                                <a href="{{ route('cajas.cerrar', $sesion) }}"
                                                   class="ms-3 text-sm font-semibold text-rose-700 hover:underline">
                                                    Cerrar
                                                </a>
                                            @endif
                                        @endcan
                                    @else
                                        @can('cajas.ver')
                                            <div class="inline-flex flex-wrap justify-end gap-x-3 gap-y-1">
                                                <a href="{{ route('cajas.corte', $sesion) }}"
                                                   class="text-sm font-semibold text-gray-900 hover:underline">
                                                    Ver corte
                                                </a>

                                                <a href="{{ route('cajas.corte.imprimir', $sesion) }}"
                                                   target="_blank"
                                                   rel="noopener"
                                                   class="text-sm font-semibold text-indigo-700 hover:underline">
                                                    Imprimir
                                                </a>

                                                <a href="{{ route('cajas.corte.pdf', $sesion) }}"
                                                   class="text-sm font-semibold text-rose-700 hover:underline">
                                                    PDF
                                                </a>

                                                <a href="{{ route('cajas.corte.xlsx', $sesion) }}"
                                                   class="text-sm font-semibold text-emerald-700 hover:underline">
                                                    XLSX
                                                </a>
                                            </div>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-5 py-8 text-center text-sm text-gray-500">
                                    Aún no hay sesiones de caja registradas.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="px-5 py-4 border-t border-gray-100">
                    {{ $sesiones->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>