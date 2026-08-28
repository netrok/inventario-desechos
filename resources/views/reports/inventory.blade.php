<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-5">

            {{-- Header --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-900 leading-tight">Inventario</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Total: <span class="font-semibold text-gray-900">{{ $total }}</span> equipos
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('reports.inventory.xlsx', request()->query()) }}"
                       class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                        XLSX
                    </a>
                    <a href="{{ route('reports.inventory.pdf', request()->query()) }}"
                       class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                        PDF
                    </a>
                </div>
            </div>

            @if($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-disc ps-5 space-y-1">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Accesos rápidos (preaplican filtro estado) --}}
            @php
                $makeEstadoUrl = function ($estado) {
                    $q = request()->query();
                    if ($estado === '') unset($q['estado']); else $q['estado'] = $estado;
                    return route('reports.inventory', $q);
                };
                $curEstado = $filters['estado'] ?? '';
            @endphp

            <div class="flex flex-wrap gap-2">
                @foreach ([
                    ['label' => 'Todos', 'key' => ''],
                    ['label' => 'Disponibles', 'key' => 'DISPONIBLE'],
                    ['label' => 'Vendidos', 'key' => 'VENDIDO'],
                    ['label' => 'Bajas', 'key' => 'BAJA'],
                ] as $qs)
                    @php $active = ($qs['key'] === '' ? ($curEstado === '' || $curEstado === null) : $curEstado === $qs['key']); @endphp
                    <a href="{{ $makeEstadoUrl($qs['key']) }}"
                       class="rounded-full border px-4 py-1.5 text-sm font-medium {{ $active ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                        {{ $qs['label'] }}
                    </a>
                @endforeach
            </div>

            {{-- Filtros --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <form method="GET" action="{{ route('reports.inventory') }}"
                      class="grid grid-cols-1 gap-3 lg:grid-cols-12 lg:items-end">

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Código</label>
                        <input name="codigo" value="{{ $filters['codigo'] ?? '' }}"
                               placeholder="ITM-000123…"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Estado</label>
                        <select name="estado" class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900">
                            <option value="">Todos</option>
                            @foreach($estados as $e)
                                <option value="{{ $e }}" @selected(($filters['estado'] ?? '') === $e)>{{ $e }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Ubicación</label>
                        <select name="ubicacion_id" class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900">
                            <option value="">Todas</option>
                            @foreach($ubicaciones as $u)
                                <option value="{{ $u->id }}" @selected((string)($filters['ubicacion_id'] ?? '') === (string)$u->id)>{{ $u->nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Categoría</label>
                        <select name="categoria_id" class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900">
                            <option value="">Todas</option>
                            @foreach($categorias as $c)
                                <option value="{{ $c->id }}" @selected((string)($filters['categoria_id'] ?? '') === (string)$c->id)>{{ $c->nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Alta desde</label>
                        <input type="date" name="alta_desde" value="{{ $filters['alta_desde']?->format('Y-m-d') ?? '' }}"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Alta hasta</label>
                        <input type="date" name="alta_hasta" value="{{ $filters['alta_hasta']?->format('Y-m-d') ?? '' }}"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Marca</label>
                        <input name="marca" value="{{ $filters['marca'] ?? '' }}" placeholder="Dell, HP…"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Modelo</label>
                        <input name="modelo" value="{{ $filters['modelo'] ?? '' }}" placeholder="Latitude…"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-12">
                        <label class="block text-xs font-semibold text-gray-600">Serie</label>
                        <input name="serie" value="{{ $filters['serie'] ?? '' }}" placeholder="SN-…"
                               class="mt-1 block w-full max-w-md rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-12 flex items-center justify-between pt-1">
                        <p class="text-xs text-gray-500">
                            Mostrando <span class="font-semibold text-gray-800">{{ $items->count() }}</span>
                            de <span class="font-semibold text-gray-800">{{ $items->total() }}</span>
                        </p>

                        <div class="flex items-center gap-2">
                            <button class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                                Filtrar
                            </button>
                            <a href="{{ route('reports.inventory') }}"
                               class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
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
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Código</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Categoría</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Marca</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Modelo</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Serie</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Estado</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Ubicación</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Fecha de alta</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Notas</th>
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
                                    <td class="px-4 py-3">
                                        <a href="{{ route('items.show', $item) }}"
                                           class="font-semibold text-gray-900 hover:underline">
                                            {{ $item->codigo }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-800">{{ $item->categoria?->nombre ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-800">{{ $item->marca ?: '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-800">{{ $item->modelo ?: '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-800">{{ $item->serie ?: '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-xs font-semibold {{ $badge }}">
                                            {{ $item->estado }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-800">{{ $item->ubicacion?->nombre ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ optional($item->created_at)->format('Y-m-d') ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500 max-w-[160px] truncate" title="{{ $item->notas ?? '' }}">
                                        {{ $item->notas ?: '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-4 py-10 text-center">
                                        <div class="text-sm font-semibold text-gray-900">Sin resultados</div>
                                        <div class="mt-1 text-sm text-gray-600">
                                            Prueba con otros filtros o limpia los filtros.
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