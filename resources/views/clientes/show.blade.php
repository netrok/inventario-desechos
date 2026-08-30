<x-app-layout title="Cliente">
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">{{ $cliente->codigo }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    {{ $cliente->nombre }} · Alta {{ $cliente->created_at?->format('d/m/Y') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <x-estado-badge :estado="$cliente->activo ? 'ACTIVO' : 'INACTIVO'" />
                @can('clientes.editar')
                    <a href="{{ route('clientes.edit', $cliente) }}"
                       class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50">
                        Editar
                    </a>
                @endcan
                @can('clientes.desactivar')
                    <form method="POST" action="{{ route('clientes.toggle', $cliente) }}" onsubmit="return confirm('¿Confirmas {{ $cliente->activo ? 'desactivar' : 'reactivar' }} a este cliente?');">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium {{ $cliente->activo ? 'border border-rose-300 bg-rose-50 text-rose-800 hover:bg-rose-100' : 'border border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100' }}">
                            {{ $cliente->activo ? 'Desactivar' : 'Reactivar' }}
                        </button>
                    </form>
                @endcan
                <a href="{{ route('clientes.index') }}"
                   class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                    ← Volver
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 space-y-5">

            @if(session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            {{-- Datos generales --}}
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Código</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $cliente->codigo }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Tipo</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $cliente->tipo }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Nombre</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $cliente->nombre }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">RFC</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $cliente->rfc ?? '—' }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Teléfono</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $cliente->telefono ?? '—' }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Email</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $cliente->email ?? '—' }}</div>
                </div>
                @if($cliente->direccion)
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 md:col-span-3">
                        <div class="text-xs text-gray-500">Dirección</div>
                        <div class="mt-1 text-sm text-gray-700">{{ $cliente->direccion }}</div>
                    </div>
                @endif
                @if($cliente->notas)
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 md:col-span-3">
                        <div class="text-xs text-gray-500">Notas</div>
                        <div class="mt-1 text-sm text-gray-700">{{ $cliente->notas }}</div>
                    </div>
                @endif
            </div>

            {{-- Historial de ventas --}}
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h3 class="text-sm font-semibold text-gray-900">Historial de ventas</h3>
                    <p class="mt-1 text-xs text-gray-500">Ventas registradas con este cliente.</p>
                </div>

                @if($ventas->isEmpty())
                    <div class="p-8 text-center text-sm text-gray-500">Sin ventas registradas.</div>
                @else
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-3">Folio</th>
                                <th class="px-5 py-3">Fecha</th>
                                <th class="px-5 py-3">Vendedor</th>
                                <th class="px-5 py-3">Estado</th>
                                <th class="px-5 py-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($ventas as $venta)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('ventas.show', $venta) }}"
                                           class="font-semibold text-gray-900 hover:underline">{{ $venta->folio }}</a>
                                    </td>
                                    <td class="px-5 py-3 text-gray-700">{{ $venta->created_at->format('d/m/Y H:i') }}</td>
                                    <td class="px-5 py-3 text-gray-700">{{ $venta->user?->name ?? '—' }}</td>
                                    <td class="px-5 py-3"><x-estado-badge :estado="$venta->estado" /></td>
                                    <td class="px-5 py-3 text-right font-semibold text-gray-900">{{ number_format((float) $venta->total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="border-t border-gray-100 px-5 py-3">
                        {{ $ventas->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
