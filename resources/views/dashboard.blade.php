<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">

            <div class="flex items-center justify-between mb-5">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">Dashboard</h1>
                    <p class="text-sm text-gray-500">Resumen del inventario.</p>
                </div>

                <a href="{{ route('items.create') }}"
                   class="inline-flex items-center rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-black">
                    + Nuevo item
                </a>
            </div>

            {{-- KPIs --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                @php
                    $cards = [
                        ['Total', $kpis->total ?? 0],
                        ['Disponible', $kpis->disponible ?? 0],
                        ['Reservado', $kpis->reservado ?? 0],
                        ['Reparación', $kpis->reparacion ?? 0],
                        ['Vendido', $kpis->vendido ?? 0],
                        ['Baja', $kpis->baja ?? 0],
                        ['Papelera', $trashCount ?? 0],
                    ];
                @endphp

                @foreach($cards as [$label, $val])
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
                        <div class="text-xs text-gray-500">{{ $label }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900">{{ $val }}</div>
                    </div>
                @endforeach
            </div>

            <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- Top ubicaciones --}}
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-sm font-semibold text-gray-900">Top ubicaciones</h2>
                        <p class="text-xs text-gray-500 mt-1">Dónde se concentra el inventario.</p>
                    </div>
                    <div class="p-6 space-y-2">
                        @forelse($topUbicaciones as $u)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-800 font-medium">{{ $u->nombre }}</span>
                                <span class="text-gray-500">{{ $u->items_count }}</span>
                            </div>
                        @empty
                            <div class="text-sm text-gray-500">Sin datos.</div>
                        @endforelse
                    </div>
                </div>

                {{-- Top categorías --}}
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-sm font-semibold text-gray-900">Top categorías</h2>
                        <p class="text-xs text-gray-500 mt-1">Clasificación más usada.</p>
                    </div>
                    <div class="p-6 space-y-2">
                        @forelse($topCategorias as $c)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-800 font-medium">{{ $c->nombre }}</span>
                                <span class="text-gray-500">{{ $c->items_count }}</span>
                            </div>
                        @empty
                            <div class="text-sm text-gray-500">Sin datos.</div>
                        @endforelse
                    </div>
                </div>

                {{-- Movimientos 7 días --}}
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-sm font-semibold text-gray-900">Movimientos (7 días)</h2>
                        <p class="text-xs text-gray-500 mt-1">Actividad reciente.</p>
                    </div>
                    <div class="p-6 space-y-2">
                        @forelse($movs7d as $d)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-700">{{ \Illuminate\Support\Carbon::parse($d->dia)->format('Y-m-d') }}</span>
                                <span class="text-gray-900 font-semibold">{{ $d->total }}</span>
                            </div>
                        @empty
                            <div class="text-sm text-gray-500">Sin datos.</div>
                        @endforelse
                    </div>
                </div>

            </div>

            {{-- Últimos movimientos --}}
            <div class="mt-6 rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-900">Últimos movimientos</h2>
                    <p class="text-xs text-gray-500 mt-1">Bitácora rápida.</p>
                </div>

                <div class="divide-y">
                    @forelse($ultMovs as $m)
                        <div class="px-6 py-4 text-sm flex items-start justify-between gap-4">
                            <div>
                                <div class="font-semibold text-gray-900">
                                    {{ $m->tipo }}
                                    <span class="text-gray-400">•</span>
                                    <span class="text-gray-700">{{ $m->item?->codigo ?? '—' }}</span>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ \Illuminate\Support\Carbon::parse($m->fecha ?? $m->created_at)->format('Y-m-d H:i') }}
                                    @if($m->user) <span class="text-gray-300">•</span> {{ $m->user->name }} @endif
                                </div>
                            </div>

                            <a href="{{ $m->item ? route('items.show', $m->item->id) : '#' }}"
                               class="text-xs text-gray-700 underline hover:text-gray-900">
                                Ver item
                            </a>
                        </div>
                    @empty
                        <div class="px-6 py-8 text-sm text-gray-500">Sin movimientos aún.</div>
                    @endforelse
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
