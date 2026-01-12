<x-app-layout>
  <div class="py-6">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">

      <div class="flex items-center justify-between gap-3 mb-5">
        <div>
          <h1 class="text-xl font-semibold text-gray-900">Nueva categoría</h1>
          <p class="text-sm text-gray-500">Crea una categoría para clasificar items.</p>
        </div>

        <a href="{{ route('categorias.index') }}"
           class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm hover:bg-gray-50">
          ← Volver
        </a>
      </div>

      <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
        <form method="POST" action="{{ route('categorias.store') }}" class="p-6">
          @csrf

          <div class="space-y-4">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
              <input name="nombre" value="{{ old('nombre') }}"
                     class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('nombre') border-rose-300 ring-rose-200 @enderror"
                     placeholder="Ej. Laptop">
              @error('nombre') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
            </div>

            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" name="activo" value="1" checked
                     class="rounded border-gray-300 text-gray-900 focus:ring-gray-900">
              Activa
            </label>
          </div>

          <div class="mt-6 flex items-center justify-end gap-2 border-t border-gray-100 pt-5">
            <a href="{{ route('categorias.index') }}"
               class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50">
              Cancelar
            </a>
            <button class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">
              Guardar
            </button>
          </div>
        </form>
      </div>

    </div>
  </div>
</x-app-layout>
