<x-app-layout>
  <div class="py-6">
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">

      <div class="flex items-center justify-between gap-3 mb-5">
        <div>
          <h1 class="text-xl font-semibold text-gray-900">Categorías</h1>
          <p class="text-sm text-gray-500">Catálogo para clasificar items.</p>
        </div>

        <div class="flex gap-2">
          <a href="{{ route('items.index') }}"
             class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm hover:bg-gray-50">
            ← Items
          </a>

          <a href="{{ route('categorias.create') }}"
             class="inline-flex items-center rounded-lg bg-gray-900 px-3 py-2 text-sm text-white hover:bg-black">
            + Nueva
          </a>
        </div>
      </div>

      @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
          {{ session('success') }}
        </div>
      @endif
      @if(session('error'))
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800">
          {{ session('error') }}
        </div>
      @endif

      <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="p-4 border-b border-gray-100">
          <form class="flex gap-2" method="GET" action="{{ route('categorias.index') }}">
            <input
              name="q"
              value="{{ $q }}"
              placeholder="Buscar categoría..."
              class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
            >
            <button class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50">
              Buscar
            </button>
          </form>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr>
                <th class="px-4 py-3 text-left font-medium">Nombre</th>
                <th class="px-4 py-3 text-left font-medium">Estado</th>
                <th class="px-4 py-3 text-right font-medium">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              @forelse($categorias as $c)
                <tr class="hover:bg-gray-50">
                  <td class="px-4 py-3 font-medium text-gray-900">{{ $c->nombre }}</td>
                  <td class="px-4 py-3">
                    @if($c->activo)
                      <span class="inline-flex rounded-full bg-emerald-100 px-2 py-1 text-xs text-emerald-800">Activa</span>
                    @else
                      <span class="inline-flex rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-700">Inactiva</span>
                    @endif
                  </td>
                  <td class="px-4 py-3">
                    <div class="flex justify-end gap-2">
                      <a href="{{ route('categorias.edit', $c) }}"
                         class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50">
                        Editar
                      </a>

                      <form method="POST" action="{{ route('categorias.destroy', $c) }}"
                            onsubmit="return confirm('¿Eliminar categoría?');">
                        @csrf
                        @method('DELETE')
                        <button class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm text-rose-700 hover:bg-rose-100">
                          Eliminar
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="3" class="px-4 py-10 text-center text-gray-500">
                    No hay categorías.
                  </td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>

        <div class="p-4 border-t border-gray-100">
          {{ $categorias->links() }}
        </div>
      </div>

    </div>
  </div>
</x-app-layout>
