<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">

            <div class="flex items-center justify-between gap-3 mb-5">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">Ubicaciones</h1>
                    <p class="text-sm text-gray-500">Catálogo para asignar items.</p>
                </div>

                @can('ubicaciones.crear')
                    <a href="{{ route('ubicaciones.create') }}"
                       class="inline-flex items-center rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-black">
                        + Nueva
                    </a>
                @endcan
            </div>

            @if(session('ok'))
                <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('ok') }}
                </div>
            @endif

            @if(session('error'))
                <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    {{ session('error') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    {{ $errors->first() }}
                </div>
            @endif

            <form class="mb-4" method="GET" action="{{ route('ubicaciones.index') }}">
                <div class="flex gap-2">
                    <input name="q" value="{{ $q ?? '' }}"
                           placeholder="Buscar ubicación..."
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                    @if(!empty($q))
                        <a href="{{ route('ubicaciones.index') }}"
                           class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm hover:bg-gray-50">
                            Limpiar
                        </a>
                    @endif
                </div>
            </form>

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Nombre</th>
                            <th class="px-4 py-3 text-left font-semibold">Items</th>
                            <th class="px-4 py-3 text-left font-semibold">Activo</th>
                            <th class="px-4 py-3 text-right font-semibold">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($ubicaciones as $u)
                            @php
                                // Si en tu query usas ->withCount('items'), esto llega.
                                $itemsCount = (int) ($u->items_count ?? 0);

                                // Evita reventar si no existe la columna "activo"
                                $hasActivo = array_key_exists('activo', $u->getAttributes());
                                $activo = $hasActivo ? (bool) $u->activo : true; // default “true” para no romper UI
                            @endphp

                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ $u->nombre }}</div>
                                    @if(!empty($u->descripcion))
                                        <div class="text-xs text-gray-500">{{ $u->descripcion }}</div>
                                    @endif
                                </td>

                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-semibold text-gray-700">
                                        {{ $itemsCount }}
                                    </span>
                                </td>

                                <td class="px-4 py-3">
                                    @if($activo)
                                        <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">Sí</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full border border-gray-200 bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700">No</span>
                                    @endif

                                    @if(!$hasActivo)
                                        <span class="ml-2 text-xs text-amber-600">(*) sin columna activo</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-right space-x-2">
                                    @can('ubicaciones.editar')
                                        <a class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs hover:bg-gray-50"
                                           href="{{ route('ubicaciones.edit', $u) }}">
                                            Editar
                                        </a>
                                    @endcan

                                    @can('ubicaciones.eliminar')
                                        @if($itemsCount > 0)
                                            <button type="button"
                                                class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-100 px-3 py-2 text-xs font-medium text-gray-500 cursor-not-allowed"
                                                title="No se puede eliminar: hay items asignados">
                                                Eliminar
                                            </button>
                                        @else
                                            <form class="inline" method="POST" action="{{ route('ubicaciones.destroy', $u) }}"
                                                  onsubmit="return confirm('¿Eliminar ubicación?');">
                                                @csrf
                                                @method('DELETE')
                                                <button class="inline-flex items-center rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700 hover:bg-rose-100">
                                                    Eliminar
                                                </button>
                                            </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-10 text-center text-gray-500">
                                    No hay ubicaciones.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="px-4 py-3 border-t bg-white">
                    {{ $ubicaciones->links() }}
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
