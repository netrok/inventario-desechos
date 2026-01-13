<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Items</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Inventario de desechos — control, trazabilidad y movimientos.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('items.create') }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                    <span class="text-base leading-none">＋</span> Nuevo
                </a>

                <a href="{{ route('items.export.xlsx', request()->query()) }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                    Exportar XLSX
                </a>

                <a href="{{ route('items.export.pdf', request()->query()) }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                    Exportar PDF
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-5">

            {{-- Flash --}}
            @if(session('ok'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 text-sm">
                    {{ session('ok') }}
                </div>
            @endif

            {{-- KPI Cards (clicables para filtrar por estado) --}}
            @php
                $curEstado = $filters['estado'] ?? '';
                $kpis = [
                    ['label'=>'Total',      'key'=>'',           'val'=>$stats->total ?? 0,      'cls'=>'bg-white',      'chip'=>'bg-gray-100 text-gray-800'],
                    ['label'=>'Disponible', 'key'=>'DISPONIBLE', 'val'=>$stats->disponible ?? 0, 'cls'=>'bg-white',      'chip'=>'bg-emerald-50 text-emerald-700'],
                    ['label'=>'Reservado',  'key'=>'RESERVADO',  'val'=>$stats->reservado ?? 0,  'cls'=>'bg-white',      'chip'=>'bg-amber-50 text-amber-700'],
                    ['label'=>'Reparación', 'key'=>'REPARACION', 'val'=>$stats->reparacion ?? 0, 'cls'=>'bg-white',      'chip'=>'bg-blue-50 text-blue-700'],
                    ['label'=>'Vendido',    'key'=>'VENDIDO',    'val'=>$stats->vendido ?? 0,    'cls'=>'bg-white',      'chip'=>'bg-slate-100 text-slate-700'],
                    ['label'=>'Baja',       'key'=>'BAJA',       'val'=>$stats->baja ?? 0,       'cls'=>'bg-white',      'chip'=>'bg-rose-50 text-rose-700'],
                ];

                $makeUrl = function ($estado) {
                    $q = request()->query();
                    if ($estado === '') unset($q['estado']); else $q['estado'] = $estado;
                    return route('items.index', $q);
                };
            @endphp

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach($kpis as $k)
                    @php
                        $active = ($k['key'] === '' && ($curEstado === '' || $curEstado === null))
                               || ($k['key'] !== '' && $curEstado === $k['key']);
                    @endphp

                    <a href="{{ $makeUrl($k['key']) }}"
                       class="group rounded-2xl border border-gray-200 bg-white p-4 shadow-sm hover:shadow transition
                              {{ $active ? 'ring-2 ring-gray-900/10 border-gray-300' : '' }}">
                        <div class="text-xs font-semibold text-gray-600">{{ $k['label'] }}</div>

                        <div class="mt-2 flex items-center justify-between">
                            <div class="text-2xl font-bold text-gray-900 tabular-nums">{{ $k['val'] }}</div>
                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $k['chip'] }}">
                                {{ $active ? 'Filtro' : 'Ver' }}
                            </span>
                        </div>

                        <div class="mt-2 text-xs text-gray-500 group-hover:text-gray-700">
                            {{ $active ? 'Activo' : 'Aplicar filtro' }}
                        </div>
                    </a>
                @endforeach
            </div>

            {{-- Filtros --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <form method="GET" action="{{ route('items.index') }}"
                      class="grid grid-cols-1 gap-3 lg:grid-cols-12 lg:items-end">

                    <div class="lg:col-span-5">
                        <label class="block text-xs font-semibold text-gray-600">Buscar</label>
                        <input
                            name="q"
                            value="{{ $filters['q'] ?? '' }}"
                            placeholder="Código, serie, marca, modelo…"
                            class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900"
                        />
                    </div>

                    <div class="lg:col-span-2">
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
                                <option value="{{ $u->id }}" @selected((string)($filters['ubicacion_id'] ?? '') === (string)$u->id)>
                                    {{ $u->nombre }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Categoría</label>
                        <select name="categoria_id"
                                class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900">
                            <option value="">Todas</option>
                            @foreach($categorias as $c)
                                <option value="{{ $c->id }}" @selected((string)($filters['categoria_id'] ?? '') === (string)$c->id)>
                                    {{ $c->nombre }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-12 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between pt-1">
                        <p class="text-xs text-gray-500">
                            Mostrando <span class="font-semibold text-gray-800">{{ $items->count() }}</span>
                            de <span class="font-semibold text-gray-800">{{ $items->total() }}</span>
                        </p>

                        <div class="flex items-center gap-2">
                            <button class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                                Filtrar
                            </button>
                            <a href="{{ route('items.index') }}"
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
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600">Item</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600">Serie</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600">Marca / Modelo</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600">Categoría</th>
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
                                    {{-- Item (Foto + Código + meta) --}}
                                    <td class="px-5 py-4">
                                        <a href="{{ route('items.show', $item) }}" class="flex items-center gap-3 group">
                                            <div class="h-11 w-11 overflow-hidden rounded-xl border border-gray-200 bg-gray-50">
                                                @if(!empty($item->foto_path))
                                                    <img src="{{ asset('storage/'.$item->foto_path) }}"
                                                         class="h-full w-full object-cover"
                                                         alt="foto"
                                                         loading="lazy">
                                                @endif
                                            </div>

                                            <div>
                                                <div class="font-semibold text-gray-900 group-hover:underline">
                                                    {{ $item->codigo }}
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    #{{ $item->id }}
                                                    <span class="text-gray-300">•</span>
                                                    {{ optional($item->updated_at)->format('Y-m-d H:i') }}
                                                </div>
                                            </div>
                                        </a>
                                    </td>

                                    <td class="px-5 py-4 text-sm text-gray-800">{{ $item->serie ?: '—' }}</td>

                                    <td class="px-5 py-4 text-sm text-gray-800">
                                        <div class="font-medium">{{ $item->marca ?: '—' }}</div>
                                        <div class="text-xs text-gray-500">{{ $item->modelo ?: '—' }}</div>
                                    </td>

                                    <td class="px-5 py-4 text-sm text-gray-800">
                                        {{ $item->categoria?->nombre ?? '—' }}
                                    </td>

                                    <td class="px-5 py-4 text-sm text-gray-800">{{ $item->ubicacion?->nombre ?: '—' }}</td>

                                    <td class="px-5 py-4">
                                        <span class="inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-xs font-semibold {{ $badge }}">
                                            <span class="h-1.5 w-1.5 rounded-full bg-current opacity-70"></span>
                                            {{ $item->estado }}
                                        </span>
                                    </td>

                                    <td class="px-5 py-4 text-right">
                                        <div class="inline-flex items-center gap-2">
                                            <a href="{{ route('items.show', $item) }}"
                                               class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
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
                                    <td colspan="7" class="px-5 py-10 text-center">
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
