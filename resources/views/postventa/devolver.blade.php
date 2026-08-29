<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Devolver equipos — {{ $venta->folio }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Registra la devolución física (parcial o total) de equipos vendidos. El importe se calcula en el servidor
                    con el precio histórico de cada detalle.
                </p>
            </div>

            <a href="{{ route('ventas.show', $venta) }}"
               class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                ← Volver al detalle
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 space-y-5">

            @if($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('ventas.devolver.store', $venta) }}" class="space-y-5"
                  x-data="{
                        total: 0,
                        updateTotal() {
                            const form = this.$el;
                            this.total = [...form.querySelectorAll('input[name=&quot;detalles[]&quot;]:checked')]
                                .reduce((acc, cb) => acc + Number(cb.dataset.importe), 0);
                        }
                  }">
                @csrf

                {{-- Selección de equipos devolubles --}}
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-3 w-10"></th>
                                <th class="px-5 py-3">Código</th>
                                <th class="px-5 py-3">Equipo</th>
                                <th class="px-5 py-3 text-right">Importe (histórico)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @php
                                $devolubles = $venta->detalles->filter(
                                    fn ($d) => $d->documentoPostventaDetalle === null && $d->item?->estado === 'VENDIDO'
                                );
                            @endphp

                            @forelse($devolubles as $detalle)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3">
                                        <input type="checkbox"
                                               name="detalles[]"
                                               value="{{ $detalle->id }}"
                                               data-importe="{{ (string) $detalle->precio }}"
                                               @change="updateTotal()"
                                               class="rounded border-gray-300">
                                    </td>
                                    <td class="px-5 py-3 font-semibold text-gray-900">{{ $detalle->item->codigo }}</td>
                                    <td class="px-5 py-3 text-gray-700">
                                        {{ collect([$detalle->item->marca, $detalle->item->modelo])->filter()->implode(' · ') ?: $detalle->item->serie ?: 'Sin descripción' }}
                                    </td>
                                    <td class="px-5 py-3 text-right font-semibold text-gray-900">{{ number_format((float) $detalle->precio, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-8 text-center text-sm text-gray-500">
                                        No hay equipos devolubles en esta venta.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="3" class="px-5 py-3 text-right text-sm font-semibold text-gray-700">Total a devolver</td>
                                <td class="px-5 py-3 text-right text-base font-bold text-gray-900">
                                    <span x-text="total.toFixed(2)" x-bind:class="total > 0 ? 'text-gray-900' : 'text-gray-400'">0.00</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                    <x-input-error :messages="$errors->get('detalles')" class="px-5 py-2" />
                </div>

                {{-- Motivo + forma de reembolso --}}
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
                    <div>
                        <x-input-label for="motivo" value="Motivo de la devolución (obligatorio)" />
                        <textarea
                            id="motivo"
                            name="motivo"
                            rows="3"
                            required
                            minlength="3"
                            maxlength="2000"
                            placeholder="Indica el motivo de la devolución…"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900 @error('motivo') border-rose-300 ring-rose-200 @enderror"
                        >{{ old('motivo') }}</textarea>
                        <x-input-error :messages="$errors->get('motivo')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="forma_reembolso" value="Forma de reembolso" />
                        <select id="forma_reembolso" name="forma_reembolso" required
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                            <option value="" disabled @selected(old('forma_reembolso') === null)>Selecciona una opción</option>
                            @foreach($formasReembolso as $forma)
                                <option value="{{ $forma }}" @selected(old('forma_reembolso') === $forma)>{{ $forma }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('forma_reembolso')" class="mt-2" />
                    </div>

                    <div class="flex gap-2">
                        <a href="{{ route('ventas.show', $venta) }}"
                           class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                            Volver
                        </a>
                        <button type="submit"
                                class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                            Confirmar devolución
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>