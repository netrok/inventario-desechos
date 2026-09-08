<x-app-layout>
    @php
        $fmt = function ($v) {
            if ($v === null || $v === '') return '—';
            return '$' . \App\Support\Money::formatear(\App\Support\Money::aPrecio(\App\Support\Money::aCentavos($v)));
        };
    @endphp

    <div class="py-6">
        <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8 space-y-5">

            {{-- Header --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-900 leading-tight">Inventario valuado</h2>
                    <p class="mt-1 text-sm text-gray-600">Valuación comercial estimada a precio de venta</p>
                    <p class="mt-1 text-xs text-gray-500">
                        Este reporte utiliza el precio de venta actual registrado en cada equipo.
                        No representa costo histórico, valor en libros ni valuación contable.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('reports.inventory-valued.xlsx', request()->query()) }}"
                       class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                        XLSX
                    </a>
                    <a href="{{ route('reports.inventory-valued.pdf', request()->query()) }}"
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

            {{-- KPIs --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold text-gray-500">Equipos actuales</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ $kpis['equipos'] }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold text-gray-500">Equipos con precio</div>
                    <div class="mt-1 text-2xl font-bold text-emerald-700">{{ $kpis['con_precio'] }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold text-gray-500">Equipos sin precio</div>
                    <div class="mt-1 text-2xl font-bold text-gray-400">{{ $kpis['sin_precio'] }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold text-gray-500">Precio cero</div>
                    <div class="mt-1 text-2xl font-bold text-amber-600">{{ $kpis['precio_cero'] }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold text-gray-500">Cobertura de valuación</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($kpis['cobertura'] * 100, 1) }}%</div>
                </div>
                <div class="rounded-2xl border border-teal-200 bg-teal-50 p-4 shadow-sm">
                    <div class="text-xs font-semibold text-teal-700">Valor comercial registrado</div>
                    <div class="mt-1 text-2xl font-bold text-teal-900">{{ $fmt($kpis['valor_comercial']) }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold text-gray-500">Valor disponible/reservado</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ $fmt($kpis['valor_disponible_reservado']) }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold text-gray-500">Valor en revisión</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ $fmt($kpis['valor_revision']) }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold text-gray-500">Valor en baja</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ $fmt($kpis['valor_baja']) }}</div>
                </div>
            </div>

            {{-- Filtros --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <form method="GET" action="{{ route('reports.inventory-valued') }}"
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
                        <label class="block text-xs font-semibold text-gray-600">Marca</label>
                        <input name="marca" value="{{ $filters['marca'] ?? '' }}" placeholder="Dell, HP…"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Modelo</label>
                        <input name="modelo" value="{{ $filters['modelo'] ?? '' }}" placeholder="Latitude…"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-3">
                        <label class="block text-xs font-semibold text-gray-600">Serie</label>
                        <input name="serie" value="{{ $filters['serie'] ?? '' }}" placeholder="SN-…"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-3">
                        <label class="block text-xs font-semibold text-gray-600">Alta desde</label>
                        <input type="date" name="alta_desde" value="{{ $filters['alta_desde']?->format('Y-m-d') ?? '' }}"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-3">
                        <label class="block text-xs font-semibold text-gray-600">Alta hasta</label>
                        <input type="date" name="alta_hasta" value="{{ $filters['alta_hasta']?->format('Y-m-d') ?? '' }}"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-3">
                        <label class="block text-xs font-semibold text-gray-600">Estado de precio</label>
                        <select name="estado_precio" class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900">
                            <option value="">Todos</option>
                            <option value="con_precio" @selected(($filters['estado_precio'] ?? '') === 'con_precio')>Con precio</option>
                            <option value="sin_precio" @selected(($filters['estado_precio'] ?? '') === 'sin_precio')>Sin precio</option>
                            <option value="precio_cero" @selected(($filters['estado_precio'] ?? '') === 'precio_cero')>Precio cero</option>
                        </select>
                    </div>

                    <div class="lg:col-span-3">
                        <label class="block text-xs font-semibold text-gray-600">Precio mínimo</label>
                        <input type="number" step="0.01" min="0" name="precio_min" value="{{ $filters['precio_min'] ?? '' }}"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-3">
                        <label class="block text-xs font-semibold text-gray-600">Precio máximo</label>
                        <input type="number" step="0.01" min="0" name="precio_max" value="{{ $filters['precio_max'] ?? '' }}"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
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
                            <a href="{{ route('reports.inventory-valued') }}"
                               class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                                Limpiar
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            {{-- Agrupaciones --}}
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                @foreach ([
                    ['title' => 'Por estado', 'rows' => $agrupaciones['estado']],
                    ['title' => 'Por categoría', 'rows' => $agrupaciones['categoria']],
                    ['title' => 'Por ubicación', 'rows' => $agrupaciones['ubicacion']],
                ] as $block)
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                        <div class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-900">{{ $block['title'] }}</div>
                        <table class="w-full divide-y divide-gray-100">
                            <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-600">
                                <tr>
                                    <th class="px-4 py-2">Grupo</th>
                                    <th class="px-2 py-2 text-right">Equipos</th>
                                    <th class="px-2 py-2 text-right">Valuados</th>
                                    <th class="px-4 py-2 text-right">Valor</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-sm">
                                @forelse($block['rows'] as $r)
                                    <tr>
                                        <td class="px-4 py-2 text-gray-800">{{ $r['grupo'] }}</td>
                                        <td class="px-2 py-2 text-right text-gray-800">{{ $r['equipos'] }}</td>
                                        <td class="px-2 py-2 text-right text-gray-600">{{ $r['con_precio'] }}</td>
                                        <td class="px-4 py-2 text-right font-medium text-gray-900">{{ $fmt($r['valor']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-4 text-center text-sm text-gray-500">Sin datos</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>

            {{-- Detalle --}}
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
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600">Precio de venta</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse($items as $item)
                                @php
                                    $badge = match($item->estado) {
                                        'DISPONIBLE' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'RESERVADO'  => 'bg-amber-50 text-amber-700 border-amber-200',
                                        'REPARACION', 'REPARACIÓN' => 'bg-blue-50 text-blue-700 border-blue-200',
                                        'DEVUELTO'   => 'bg-indigo-50 text-indigo-700 border-indigo-200',
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
                                    <td class="px-4 py-3 text-right text-sm font-medium text-gray-900">
                                        @if($item->precio === null)
                                            <span class="text-gray-400">Sin precio</span>
                                        @elseif(\App\Support\Money::aCentavos($item->precio) === 0)
                                            <span class="text-amber-600 font-semibold">$0.00</span>
                                        @else
                                            {{ $fmt($item->precio) }}
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-10 text-center">
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
