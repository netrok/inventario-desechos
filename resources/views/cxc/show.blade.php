<x-app-layout :title="$cuenta->folio">
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-semibold text-gray-900 leading-tight">{{ $cuenta->folio }}</h2>
                    <x-estado-badge :estado="$cuenta->estado" />
                    @if($cuenta->esVencida())
                        <span class="inline-flex rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 border border-rose-200">Vencida</span>
                    @endif
                </div>
                <p class="mt-1 text-sm text-gray-600">
                    Cuenta por cobrar de la venta a crédito.
                </p>
            </div>
            <div class="text-sm">
                <a href="{{ route('cxc.index') }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 font-semibold text-gray-800 hover:bg-gray-50">
                    Volver a Cuentas por cobrar
                </a>
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

            {{-- Datos de la cuenta --}}
            <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
                <div class="text-xs text-indigo-700 uppercase tracking-wide">Detalle de la cuenta</div>
                <div class="mt-2 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div>
                        <div class="text-[11px] text-indigo-600">Cliente</div>
                        @if($cuenta->cliente)
                            <div class="font-semibold text-gray-900">{{ $cuenta->cliente->nombre }}</div>
                            <div class="text-xs text-indigo-700">{{ $cuenta->cliente->codigo }}</div>
                        @else
                            <div class="font-semibold text-gray-900">—</div>
                        @endif
                    </div>
                    <div>
                        <div class="text-[11px] text-indigo-600">Venta origen</div>
                        @if($cuenta->venta)
                            <a href="{{ route('ventas.show', $cuenta->venta) }}"
                               class="font-semibold text-indigo-700 underline decoration-indigo-300 hover:text-indigo-900">
                                {{ $cuenta->venta->folio }}
                            </a>
                        @else
                            <div class="font-semibold text-gray-900">—</div>
                        @endif
                    </div>
                    <div>
                        <div class="text-[11px] text-indigo-600">Fecha de origen</div>
                        <div class="font-semibold text-gray-900">{{ $cuenta->created_at?->format('d/m/Y H:i') }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] text-indigo-600">Fecha de vencimiento</div>
                        <div class="font-semibold text-gray-900">{{ $cuenta->fecha_vencimiento?->format('d/m/Y') ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] text-indigo-600">Importe original</div>
                        <div class="font-semibold text-gray-900">${{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($cuenta->importe_original_centavos)) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] text-indigo-600">Saldo actual</div>
                        <div class="font-semibold text-gray-900">${{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($cuenta->saldo_centavos)) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] text-indigo-600">Plazo aplicado (snapshot)</div>
                        <div class="font-semibold text-gray-900">{{ $cuenta->dias_credito_aplicados }} día(s)</div>
                    </div>
                    <div>
                        <div class="text-[11px] text-indigo-600">Vencida</div>
                        <div class="font-semibold text-gray-900">{{ $cuenta->esVencida() ? 'Sí' : 'No' }}</div>
                    </div>
                </div>
            </div>

            @php $admiteAbonos = in_array($cuenta->estado, ['PENDIENTE', 'PARCIAL'], true); @endphp

            {{-- Formulario de abono --}}
            @can('cxc.abonar')
                @if($admiteAbonos)
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="text-sm font-semibold text-gray-900">Registrar abono</div>
                        @if(! $sesionAbierta)
                            <div class="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                Necesitas una caja abierta para registrar abonos en efectivo. Los abonos con
                                tarjeta o transferencia se registran sin caja.
                            </div>
                        @endif
                        <form method="POST" action="{{ route('cxc.abonos.store', $cuenta) }}" class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                            @csrf
                            <input type="hidden" name="operacion_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <div>
                                <label for="monto" class="text-xs text-gray-500">Monto (máx. saldo: ${{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($cuenta->saldo_centavos)) }})</label>
                                <input id="monto" type="number" name="monto" step="0.01" min="0.01" required
                                       class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                            </div>
                            <div>
                                <label for="metodo" class="text-xs text-gray-500">Método</label>
                                <select id="metodo" name="metodo" required
                                        class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                                    <option value="EFECTIVO">Efectivo</option>
                                    <option value="TARJETA">Tarjeta</option>
                                    <option value="TRANSFERENCIA">Transferencia</option>
                                </select>
                            </div>
                            <div>
                                <label for="referencia" class="text-xs text-gray-500">Referencia (obligatoria para tarjeta/transferencia)</label>
                                <input id="referencia" type="text" name="referencia" maxlength="100"
                                       class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                            </div>
                            <div class="lg:col-span-2">
                                <label for="observaciones" class="text-xs text-gray-500">Observaciones (opcional)</label>
                                <input id="observaciones" type="text" name="observaciones" maxlength="500"
                                       class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                            </div>
                            <div class="lg:col-span-5 flex justify-end">
                                <button type="submit"
                                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                                    Registrar abono
                                </button>
                            </div>
                        </form>
                    </div>
                @else
                    <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                        Esta cuenta no admite nuevos abonos en su estado actual ({{ $cuenta->estado }}). Su historial de movimientos se conserva.
                    </div>
                @endif
            @endcan

            {{-- Ledger (append-only; sin editar ni eliminar) --}}
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="px-5 py-3 border-b border-gray-100 text-sm font-semibold text-gray-900">
                    Movimientos (historial)
                </div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-3">Fecha</th>
                            <th class="px-5 py-3">Tipo</th>
                            <th class="px-5 py-3 text-right">Monto</th>
                            <th class="px-5 py-3 text-right">Saldo antes</th>
                            <th class="px-5 py-3 text-right">Saldo después</th>
                            <th class="px-5 py-3">Método</th>
                            <th class="px-5 py-3">Referencia</th>
                            <th class="px-5 py-3">Usuario</th>
                            <th class="px-5 py-3">Observaciones</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($movimientos as $m)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 text-gray-700">{{ $m->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-800">{{ $m->tipo }}</span>
                                    @if($m->documentoPostventa)
                                        <a href="{{ route('postventa.show', $m->documentoPostventa) }}"
                                           class="ml-1 text-xs font-semibold text-indigo-700 hover:underline">
                                            {{ $m->documentoPostventa->folio }}
                                        </a>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right text-gray-900">
                                    ${{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($m->monto_centavos)) }}
                                </td>
                                <td class="px-5 py-3 text-right text-gray-700">
                                    ${{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($m->saldo_antes_centavos)) }}
                                </td>
                                <td class="px-5 py-3 text-right font-semibold text-gray-900">
                                    ${{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($m->saldo_despues_centavos)) }}
                                </td>
                                <td class="px-5 py-3 text-gray-700">
                                    {{ $m->metodoOriginal() ?? '—' }}
                                </td>
                                <td class="px-5 py-3 text-gray-700">{{ $m->referencia ?? '—' }}</td>
                                <td class="px-5 py-3 text-gray-700">{{ $m->user?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-gray-700">{{ $m->observaciones ?? '—' }}</td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    @if(\App\Support\CxCAcceso::puedeReversar(auth()->user()))
                                        @if($m->tipo === 'ABONO' && ! $m->reversa)
                                            <form method="POST" action="{{ route('cxc.abonos.reversar', [$cuenta, $m]) }}"
                                                  class="inline-flex items-center gap-2">
                                                @csrf
                                                <input type="text" name="motivo" maxlength="500" required
                                                       placeholder="Motivo de la reversa"
                                                       class="w-44 rounded-lg border-rose-200 text-xs text-rose-800 placeholder-rose-300 focus:border-rose-400 focus:ring-rose-400">
                                                <button type="submit"
                                                        class="rounded-lg border border-rose-200 bg-rose-50 px-2 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                                                    Reversar abono
                                                </button>
                                            </form>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-5 py-10 text-center text-sm text-gray-500">
                                    Sin movimientos registrados.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
