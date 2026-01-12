<x-app-layout>
    <div class="p-6 space-y-4">
        @if(session('ok'))
            <div class="p-3 rounded bg-green-100">{{ session('ok') }}</div>
        @endif

        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Papelera de Items</h1>
            <a href="{{ route('items.index') }}" class="px-4 py-2 rounded bg-gray-200">Volver</a>
        </div>

        <form class="flex gap-2">
            <input name="q" value="{{ $q ?? '' }}" placeholder="Buscar código/serie" class="border rounded px-3 py-2" />
            <button class="px-4 py-2 rounded bg-gray-800 text-white">Buscar</button>
        </form>

        <div class="overflow-auto border rounded">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                <tr>
                    <th class="p-2 text-left">Código</th>
                    <th class="p-2 text-left">Serie</th>
                    <th class="p-2 text-left">Eliminado</th>
                    <th class="p-2 text-right">Acciones</th>
                </tr>
                </thead>
                <tbody>
                @forelse($items as $it)
                    <tr class="border-t">
                        <td class="p-2 font-medium">{{ $it->codigo }}</td>
                        <td class="p-2">{{ $it->serie }}</td>
                        <td class="p-2">{{ $it->deleted_at }}</td>
                        <td class="p-2 text-right space-x-2">
                            <form class="inline" method="POST" action="{{ route('items.restore', $it->id) }}">
                                @csrf
                                <button class="px-3 py-1 rounded bg-green-600 text-white"
                                        onclick="return confirm('¿Restaurar este item?')">
                                    Restaurar
                                </button>
                            </form>

                            <form class="inline" method="POST" action="{{ route('items.forceDelete', $it->id) }}">
                                @csrf @method('DELETE')
                                <button class="px-3 py-1 rounded bg-red-600 text-white"
                                        onclick="return confirm('Esto lo borra para siempre. ¿Seguro?')">
                                    Borrar definitivo
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="p-4" colspan="4">Papelera vacía.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $items->links() }}
    </div>
</x-app-layout>
