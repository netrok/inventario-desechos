<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Configuración de cajas</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Administración de las cajas físicas que abren sesiones. Los movimientos,
                    arqueos y cortes se consultan en «Caja». Una caja con sesión abierta no puede desactivarse.
                </p>
            </div>

            <div class="flex items-center gap-2">
                @can('cajas.configurar')
                    <a href="{{ route('cajas.gestion.crear') }}"
                       class="inline-flex items-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                        + Crear caja
                    </a>
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

            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-x-auto">
                <div class="border-b border-gray-100 px-5 py-4 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900">Cajas físicas ({{ $cajas->count() }})</h3>
                    <span class="text-xs text-gray-500">El código se asigna automáticamente y no cambia.</span>
                </div>

                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-3">Código</th>
                            <th class="px-5 py-3">Nombre</th>
                            <th class="px-5 py-3">Operador asignado</th>
                            <th class="px-5 py-3">Estado</th>
                            <th class="px-5 py-3">Sesión abierta</th>
                            <th class="px-5 py-3">Sesión abierta por</th>
                            <th class="px-5 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($cajas as $caja)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 font-mono text-xs font-semibold text-gray-900">{{ $caja->codigo }}</td>
                                <td class="px-5 py-3 font-semibold text-gray-900">{{ $caja->nombre }}</td>
                                <td class="px-5 py-3 text-gray-700">
                                    @if($caja->usuario_asignado_id)
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                            {{ $caja->usuarioAsignado?->name ?? 'Operador #'.$caja->usuario_asignado_id }}
                                        </span>
                                    @else
                                        <span class="text-gray-400">Sin asignar</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold
                                        {{ $caja->activa ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">
                                        {{ $caja->activa ? 'ACTIVA' : 'INACTIVA' }}
                                    </span>
                                </td>
                                @php $abierta = $caja->sesionesAbiertas->first(); @endphp
                                <td class="px-5 py-3 text-gray-700">{{ $abierta?->folio ?? '—' }}</td>
                                <td class="px-5 py-3 text-gray-700">{{ $abierta?->usuarioApertura?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('cajas.gestion.editar', $caja) }}"
                                       class="text-sm font-semibold text-gray-900 hover:underline">
                                        Editar
                                    </a>
                                    @if($abierta)
                                        <span class="ms-2 text-xs text-amber-700">no se puede desactivar</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-8 text-center text-sm text-gray-500">
                                    Aún no hay cajas registradas. Crea la primera.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
