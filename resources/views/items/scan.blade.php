<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">

            <div class="flex items-center justify-between gap-3 mb-5">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">Escanear / buscar equipo</h1>
                    <p class="text-sm text-gray-500">Escanea o escribe el código del equipo.</p>
                </div>

                <a href="{{ route('items.index') }}"
                   class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm hover:bg-gray-50">
                    ← Volver
                </a>
            </div>

            @if(!empty($error))
                <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    {{ $error }}
                </div>
            @endif

            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                <form method="GET"
                      action="{{ route('items.scan') }}"
                      class="p-6 space-y-4">
                    <div>
                        <label for="codigo" class="block text-xs font-medium text-gray-600 mb-1">
                            Código del equipo
                        </label>
                        <input
                            name="codigo"
                            id="codigo"
                            value="{{ $last_codigo ?? '' }}"
                            placeholder="ITM-000123"
                            autofocus
                            autocomplete="off"
                            spellcheck="false"
                            class="w-full rounded-lg border-gray-300 text-2xl tracking-widest text-center uppercase focus:border-gray-900 focus:ring-gray-900"
                        >
                    </div>

                    <p class="text-xs text-gray-500">
                        Presiona Enter tras escanear. El código funciona con espacios y en minúsculas.
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit"
                                class="inline-flex items-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">
                            Buscar
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>