<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">{{ $venta->folio }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Comprobante de la venta. {{ $venta->created_at->format('d/m/Y H:i') }}
                </p>
            </div>

            <a href="{{ route('ventas.index') }}"
               class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                ← Volver a ventas
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 space-y-5">

            @if(session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

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

            @if($venta->notas)
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Notas</div>
                    <p class="mt-1 text-sm text-gray-700">{{ $venta->notas }}</p>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>