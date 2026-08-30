<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h1 class="text-xl font-semibold text-gray-900">Reportes</h1>
                <p class="mt-1 text-sm text-gray-600">
                    Consulta operativa de inventario y trazabilidad de movimientos.
                </p>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

                {{-- Inventario --}}
                <a href="{{ route('reports.inventory') }}"
                   class="group rounded-2xl border border-gray-200 bg-white p-6 shadow-sm hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">Inventario</h2>
                        <span class="text-sm font-medium text-gray-500 group-hover:text-gray-900">
                            Ver →
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-gray-600">
                        Equipos con filtros por estado, ubicación, categoría, marca, modelo y serie.
                        Con exportación XLSX / PDF.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-700">Disponibles</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-700">Vendidos</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-700">Bajas</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-700">Por ubicación</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-700">Por categoría</span>
                    </div>
                </a>

                {{-- Movimientos --}}
                <a href="{{ route('reports.movimientos') }}"
                   class="group rounded-2xl border border-gray-200 bg-white p-6 shadow-sm hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">Movimientos</h2>
                        <span class="text-sm font-medium text-gray-500 group-hover:text-gray-900">
                            Ver →
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-gray-600">
                        ALTA, CAMBIO_ESTADO, TRASLADO, AJUSTE, BAJA y VENTA con filtros por
                        periodo, usuario, tipo, Item y ubicaciones.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-700">Periodo</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-700">Por usuario</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-700">Por Item</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-700">Historial completo</span>
                    </div>
                </a>
            </div>

        </div>
    </div>
</x-app-layout>