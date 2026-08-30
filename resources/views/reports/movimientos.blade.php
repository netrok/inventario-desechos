<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8 space-y-5">

            {{-- Header --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-900 leading-tight">Movimientos</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Total: <span class="font-semibold text-gray-900">{{ $total }}</span> movimientos
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('reports.movimientos.xlsx', request()->query()) }}"
                       class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                        XLSX
                    </a>
                    <a href="{{ route('reports.movimientos.pdf', request()->query()) }}"
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

            {{-- Filtros --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <form method="GET" action="{{ route('reports.movimientos') }}"
                      class="grid grid-cols-1 gap-3 lg:grid-cols-12 lg:items-end">

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Desde</label>
                        <input type="date" name="desde" value="{{ $filters['desde']?->format('Y-m-d') ?? '' }}"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Hasta</label>
                        <input type="date" name="hasta" value="{{ $filters['hasta']?->format('Y-m-d') ?? '' }}"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Usuario</label>
                        <select name="usuario_id" class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900">
                            <option value="">Todos</option>
                            @foreach($usuarios as $u)
                                <option value="{{ $u->id }}" @selected((string)($filters['usuario_id'] ?? '') === (string)$u->id)>{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Tipo</label>
                        <select name="tipo" class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900">
                            <option value="">Todos</option>
                            @foreach($tipos as $t)
                                <option value="{{ $t }}" @selected(($filters['tipo'] ?? '') === $t)>{{ $t }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Código Item</label>
                        <input name="codigo" value="{{ $filters['codigo'] ?? '' }}" placeholder="ITM-000123…"
                               class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900" />
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Ubicación origen</label>
                        <select name="ubicacion_origen_id" class="mt-1 w-full rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900">
                            <option value="">Todas</option>
                            @foreach($ubicaciones as $u)
                                <option value="{{ $u->id }}" @selected((string)($filters['ubicacion_origen_id'] ?? '') === (string)$u->id)>{{ $u->nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-12">
                        <label class="block text-xs font-semibold text-gray-600">Ubicación destino</label>
                        <select name="ubicacion_destino_id" class="mt-1 block w-full max-w-md rounded-lg border-gray-200 focus:border-gray-900 focus:ring-gray-900">
                            <option value="">Todas</option>
                            @foreach($ubicaciones as $u)
                                <option value="{{ $u->id }}" @selected((string)($filters['ubicacion_destino_id'] ?? '') === (string)$u->id)>{{ $u->nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-12 flex items-center justify-between pt-1">
                        <p class="text-xs text-gray-500">
                            Mostrando <span class="font-semibold text-gray-800">{{ $movimientos->count() }}</span>
                            de <span class="font-semibold text-gray-800">{{ $movimientos->total() }}</span>
                        </p>

                        <div class="flex items-center gap-2">
                            <button class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                                Filtrar
                            </button>
                            <a href="{{ route('reports.movimientos') }}"
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
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Fecha</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Item</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Tipo</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Usuario</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Estado</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Ubicación</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600">Notas</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse($movimientos as $m)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                                        {{ optional($m->created_at)->format('Y-m-d H:i') }}
                                        @if($m->evidencia_path)
                                            <a href="{{ asset('storage/'.$m->evidencia_path) }}" target="_blank"
                                               class="ml-1 text-xs text-gray-600 underline hover:text-gray-900">
                                                evidencia
                                            </a>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('items.show', $m->item_id) }}"
                                           class="font-semibold text-gray-900 hover:underline">
                                            {{ $m->item?->codigo ?? '#' . $m->item_id }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-800">{{ $m->tipo }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-800">{{ $m->user?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">
                                        {{ $m->de_estado ? ($m->de_estado.' → ') : '— → ' }}
                                        {{ $m->a_estado ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-700">
                                        {{ $m->deUbicacion?->nombre ?? '—' }} → {{ $m->aUbicacion?->nombre ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500 max-w-[200px] truncate" title="{{ $m->notas ?? '' }}">
                                        {{ $m->notas ?: '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-10 text-center">
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
                    {{ $movimientos->links() }}
                </div>
            </div>

        </div>
    </div>
</x-app-layout>