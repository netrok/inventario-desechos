<x-app-layout title="Clientes">
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Clientes</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Catálogo de personas y empresas. Sin borrado físico: activo/inactivo.
                </p>
            </div>

            @can('clientes.crear')
                <a href="{{ route('clientes.create') }}"
                   class="inline-flex items-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                    + Nuevo cliente
                </a>
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

            {{-- Filtros --}}
            <form method="GET" action="{{ route('clientes.index') }}" class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <div class="sm:col-span-2">
                        <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Buscar por nombre, código, RFC, teléfono o email…"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                    </div>
                    <select name="tipo" class="rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                        <option value="">Todos los tipos</option>
                        @foreach($tipos as $t)
                            <option value="{{ $t }}" @selected($filters['tipo'] === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                    <select name="activo" class="rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                        <option value="">Todos</option>
                        <option value="1" @selected($filters['activo'] === '1')>Activos</option>
                        <option value="0" @selected($filters['activo'] === '0')>Inactivos</option>
                    </select>
                </div>
                <div class="mt-3 flex gap-2">
                    <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">Filtrar</button>
                    <a href="{{ route('clientes.index') }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">Limpiar</a>
                </div>
            </form>

            @if($clientes->isEmpty())
                <div class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">
                    No hay clientes registrados.
                </div>
            @else
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-3">Código</th>
                                <th class="px-5 py-3">Nombre</th>
                                <th class="px-5 py-3">Tipo</th>
                                <th class="px-5 py-3">RFC</th>
                                <th class="px-5 py-3">Teléfono</th>
                                <th class="px-5 py-3 text-right">Ventas</th>
                                <th class="px-5 py-3">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($clientes as $cliente)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('clientes.show', $cliente) }}"
                                           class="font-semibold text-gray-900 hover:underline">{{ $cliente->codigo }}</a>
                                    </td>
                                    <td class="px-5 py-3 text-gray-700">{{ $cliente->nombre }}</td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                            {{ $cliente->tipo }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-gray-700">{{ $cliente->rfc ?? '—' }}</td>
                                    <td class="px-5 py-3 text-gray-700">{{ $cliente->telefono ?? '—' }}</td>
                                    <td class="px-5 py-3 text-right text-gray-700">{{ $cliente->ventas_count }}</td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $cliente->activo ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                            {{ $cliente->activo ? 'ACTIVO' : 'INACTIVO' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    {{ $clientes->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
