<x-app-layout>
    <div class="p-6 space-y-6">
        <h1 class="text-2xl font-semibold">Dashboard</h1>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="border rounded p-4">
                <div class="text-sm text-gray-500">Total items</div>
                <div class="text-3xl font-bold">{{ $total }}</div>
            </div>
            <div class="border rounded p-4">
                <div class="text-sm text-gray-500">Disponibles</div>
                <div class="text-3xl font-bold">{{ $porEstado['DISPONIBLE'] ?? 0 }}</div>
            </div>
            <div class="border rounded p-4">
                <div class="text-sm text-gray-500">Reservados</div>
                <div class="text-3xl font-bold">{{ $porEstado['RESERVADO'] ?? 0 }}</div>
            </div>
            <div class="border rounded p-4">
                <div class="text-sm text-gray-500">Papelera</div>
                <div class="text-3xl font-bold">{{ $enPapelera }}</div>
            </div>
        </div>

        <div class="border rounded p-4">
            <h2 class="font-semibold mb-3">Totales por estado</h2>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                @foreach(\App\Models\Item::ESTADOS as $e)
                    <div class="border rounded p-3">
                        <div class="text-xs text-gray-500">{{ $e }}</div>
                        <div class="text-xl font-bold">{{ $porEstado[$e] ?? 0 }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="border rounded overflow-auto">
            <div class="p-3 font-semibold bg-gray-100">Items por ubicación</div>
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="p-2 text-left">Ubicación</th>
                        <th class="p-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($porUbicacion as $u)
                        <tr class="border-t">
                            <td class="p-2">{{ $u->nombre }}</td>
                            <td class="p-2 text-right">{{ $u->items_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex gap-2">
            <a class="px-4 py-2 rounded bg-gray-200" href="{{ route('items.index') }}">Ir a Items</a>
            <a class="px-4 py-2 rounded bg-gray-200" href="{{ route('items.trash') }}">Ver Papelera</a>
        </div>
    </div>
</x-app-layout>
