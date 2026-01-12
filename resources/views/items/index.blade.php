<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-xl font-semibold text-gray-900 leading-tight">Items</h2>
            <p class="mt-1 text-sm text-gray-600">
                Inventario de desechos — control, trazabilidad y movimientos.
            </p>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-4">

            {{-- Flash --}}
            @if(session('ok'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 text-sm">
                    {{ session('ok') }}
                </div>
            @endif

            {{-- KPIs compactos (chips) --}}
            <div class="flex flex-wrap gap-2">
                @php
                    $k = [
                        ['Total', $stats->total ?? 0, 'bg-gray-100 text-gray-800'],
                        ['Disponible', $stats->disponible ?? 0, 'bg-emerald-50 text-emerald-700'],
                        ['Reservado', $stats->reservado ?? 0, 'bg-amber-50 text-amber-700'],
                        ['Reparación', $stats->reparacion ?? 0, 'bg-blue-50 text-blue-700'],
                        ['Vendido', $stats->vendido ?? 0, 'bg-slate-100 text-slate-700'],
                        ['Baja', $stats->baja ?? 0, 'bg-rose-50 text-rose-700'],
                    ];
                @endphp

                @foreach($k as [$label, $value, $cls])
                    <div class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm {{ $cls }}">
                        <span class="font-medium">{{ $label }}</span>
                        <span class="inline-flex items-center justify-center min-w-[1.75rem] px-2 py-0.5 rounded-full bg-white/70 text-xs font-semibold">
                            {{ $value }}
                        </span>
                    </div>
                @endforeach
            </div>

            {{-- Filtros --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <form method="GET" action="{{ route('items.index') }}"
                      class="grid grid-cols-1 gap-3 lg:grid-cols-12 lg:items-end">

                    <div class="lg:col-span-6">
                        <label class="block text-xs font-semibold text-gray-600">Buscar</label>
                        <input
                            name="q"
                            value="{{ $filters['q'] ?? '' }}"
                            placeholder="Código, serie, marca, modelo…"
                            class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900"
                        />
                    </div>

                    <div class="lg:col-span-3">
                        <label class="block text-xs font-semibold text-gray-600">Estado</label>
                        <select name="estado"
                                class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900">
                            <option value="">Todos</option>
                            @foreach($estados as $e)
                                <option value="{{ $e }}" @selected(($filters['estado'] ?? '') === $e)>{{ $e }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-3">
                        <label class="block text-xs font-semibold text-gray-600">Ubicación</label>
                        <select name="ubicacion_id"
                                class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900">
                            <option value="">Todas</option>
                            @foreach($ubicaciones as $u)
                                <option value="{{ $u->id }}"
                                    @selected((string)($filters['ubicacion_id'] ?? '') === (string)$u->id)>
                                    {{ $u->nombre }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-12 flex items-center justify-between pt-1">
                        <p class="text-xs text-gray-500">
                            Mostrando {{ $items->count() }} de {{ $items->total() }}
                        </p>

                        <div class="flex items-center gap-2">
                            <button class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                                Filtrar
                            </button>
                            <a href="{{ route('items.index') }}"
                               class="text-sm font-medium text-gray-700 hover:text-gray-900">
                                Limpiar
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            {{-- Tabla --}}
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600">Foto</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600">Código</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600">Serie</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600">Marca</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600">Modelo</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600">Ubicación</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600">Estado</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600">Acciones</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse($items as $item)
                                @php
                                    $badge = match($item->estado) {
                                        'DISPONIBLE' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'RESERVADO'  => 'bg-amber-50 text-amber-700 border-amber-200',
                                        'REPARACION', 'REPARACIÓN' => 'bg-blue-50 text-blue-700 border-blue-200',
                                        'VENDIDO'    => 'bg-slate-100 text-slate-700 border-slate-200',
                                        'BAJA'       => 'bg-rose-50 text-rose-700 border-rose-200',
                                        default      => 'bg-gray-50 text-gray-700 border-gray-200',
                                    };
                                @endphp

                                <tr class="hover:bg-gray-50">
                                    {{-- Foto --}}
                                    <td class="px-5 py-4">
                                        @if(!empty($item->foto_path))
                                            <img
                                                src="{{ asset('storage/'.$item->foto_path) }}"
                                                class="h-10 w-10 rounded-lg object-cover border border-gray-200"
                                                alt="foto"
                                                loading="lazy"
                                            >
                                        @else
                                            <div class="h-10 w-10 rounded-lg border border-dashed border-gray-300 bg-gray-50"></div>
                                        @endif
                                    </td>

                                    {{-- Código --}}
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-900">{{ $item->codigo }}</div>
                                        <div class="text-xs text-gray-500">#{{ $item->id }}</div>
                                    </td>

                                    <td class="px-5 py-4 text-sm text-gray-800">{{ $item->serie ?: '—' }}</td>
                                    <td class="px-5 py-4 text-sm text-gray-800">{{ $item->marca ?: '—' }}</td>
                                    <td class="px-5 py-4 text-sm text-gray-800">{{ $item->modelo ?: '—' }}</td>
                                    <td class="px-5 py-4 text-sm text-gray-800">{{ $item->ubicacion?->nombre ?: '—' }}</td>

                                    {{-- Estado --}}
                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $badge }}">
                                            {{ $item->estado }}
                                        </span>
                                    </td>

                                    {{-- Acciones --}}
                                    <td class="px-5 py-4 text-right">
                                        <div class="inline-flex items-center gap-2">
                                            <a href="{{ route('items.show', $item) }}"
                                               class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50">
                                                Ver
                                            </a>
                                            <a href="{{ route('items.edit', $item) }}"
                                               class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-black">
                                                Editar
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-5 py-10 text-center">
                                        <div class="text-sm font-semibold text-gray-900">Sin resultados</div>
                                        <div class="mt-1 text-sm text-gray-600">
                                            Prueba con otros filtros o quita los filtros.
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-4 py-3 bg-white border-t border-gray-100">
                    {{ $items->links() }}
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
