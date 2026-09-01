<x-app-layout title="Cuentas por cobrar">
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Cuentas por cobrar</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Cobranza y abonos de las ventas a crédito.
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8 space-y-5">

            @if(session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            {{-- Resumen: todo en centavos/string, sin float --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500">Saldo total</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">${{ $saldoTotal }}</div>
                </div>
                <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4">
                    <div class="text-xs text-rose-600">Saldo vencido</div>
                    <div class="mt-1 text-xl font-bold text-rose-800">${{ $saldoVencido }}</div>
                </div>
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <div class="text-xs text-amber-700">Cuentas con saldo</div>
                    <div class="mt-1 text-xl font-bold text-amber-800">{{ number_format($cuentasActivas) }}</div>
                </div>
            </div>

            {{-- Filtros --}}
            <form method="GET" action="{{ route('cxc.index') }}" class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <div>
                        <input type="text" name="folio" value="{{ $filtros['folio'] }}" placeholder="Folio (ej. CXC-000001)"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                    </div>
                    <div>
                        <input type="text" name="cliente" value="{{ $filtros['cliente'] }}" placeholder="Cliente (nombre o código)"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                    </div>
                    <div>
                        <select name="estado" class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                            <option value="">Todos los estados</option>
                            @foreach($estados as $e)
                                <option value="{{ $e }}" @selected($filtros['estado'] === $e)>{{ $e }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700">
                            <input type="checkbox" name="vencidas" value="1" @checked($filtros['vencidas'])
                                   class="rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                            Vencidas
                        </label>
                    </div>
                    <div>
                        <label class="flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700">
                            <input type="checkbox" name="con_saldo" value="1" @checked($filtros['con_saldo'])
                                   class="rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                            Con saldo
                        </label>
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">Filtrar</button>
                    <a href="{{ route('cxc.index') }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">Limpiar</a>
                </div>
            </form>

            @if($cuentas->isEmpty())
                <div class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">
                    No hay cuentas por cobrar que coincidan con los filtros.
                </div>
            @else
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-3">Folio CxC</th>
                                <th class="px-5 py-3">Cliente</th>
                                <th class="px-5 py-3">Venta</th>
                                <th class="px-5 py-3">Fecha origen</th>
                                <th class="px-5 py-3">Vencimiento</th>
                                <th class="px-5 py-3 text-right">Importe original</th>
                                <th class="px-5 py-3 text-right">Saldo</th>
                                <th class="px-5 py-3">Estado</th>
                                <th class="px-5 py-3">Vencida</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($cuentas as $cuenta)
                                @php $esVencida = $cuenta->esVencida(); @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('cxc.show', $cuenta) }}"
                                           class="font-semibold text-gray-900 hover:underline">
                                            {{ $cuenta->folio }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 text-gray-700">
                                        <div class="text-gray-900">{{ $cuenta->cliente?->nombre ?? '—' }}</div>
                                        @if($cuenta->cliente)
                                            <div class="text-xs text-gray-400">{{ $cuenta->cliente->codigo }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @if($cuenta->venta)
                                            <a href="{{ route('ventas.show', $cuenta->venta) }}"
                                               class="text-gray-700 hover:underline">
                                                {{ $cuenta->venta->folio }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-gray-700">{{ $cuenta->created_at?->format('d/m/Y') }}</td>
                                    <td class="px-5 py-3 text-gray-700">{{ $cuenta->fecha_vencimiento?->format('d/m/Y') }}</td>
                                    <td class="px-5 py-3 text-right text-gray-700">
                                        {{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($cuenta->importe_original_centavos)) }}
                                    </td>
                                    <td class="px-5 py-3 text-right font-semibold text-gray-900">
                                        {{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($cuenta->saldo_centavos)) }}
                                    </td>
                                    <td class="px-5 py-3">
                                        <x-estado-badge :estado="$cuenta->estado" />
                                    </td>
                                    <td class="px-5 py-3">
                                        @if($esVencida)
                                            <span class="inline-flex rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700">Vencida</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">No</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    {{ $cuentas->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
