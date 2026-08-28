<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Ventas</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Historial de ventas registradas desde el punto de venta.
                </p>
            </div>

            @can('ventas.crear')
                <a href="{{ route('pos.index') }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                    Punto de venta
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-5">

            @if(session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            @if($ventas->isEmpty())
                <div class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">
                    Todavía no hay ventas registradas.
                </div>
            @else
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-3">Folio</th>
                                <th class="px-5 py-3">Fecha</th>
                                <th class="px-5 py-3">Usuario</th>
                                <th class="px-5 py-3 text-right">Equipos</th>
                                <th class="px-5 py-3">Forma de pago</th>
                                <th class="px-5 py-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($ventas as $venta)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('ventas.show', $venta) }}"
                                           class="font-semibold text-gray-900 hover:underline">
                                            {{ $venta->folio }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 text-gray-700">{{ $venta->created_at->format('d/m/Y H:i') }}</td>
                                    <td class="px-5 py-3 text-gray-700">{{ $venta->user?->name ?? '—' }}</td>
                                    <td class="px-5 py-3 text-right text-gray-700">{{ $venta->detalles_count }}</td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                            {{ $venta->forma_pago }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-right font-semibold text-gray-900">{{ number_format((float) $venta->total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    {{ $ventas->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>