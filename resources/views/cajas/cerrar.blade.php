<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Corte de sesión {{ $sesion->folio }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Arqueo final bajo corte ciego. Cierra la sesión y deja los números inmutables.
                </p>
            </div>

            <a href="{{ route('cajas.movimientos', $sesion) }}"
               class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                ← Volver a la sesión
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 space-y-5">

            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <strong>Atención:</strong> el cierre es <strong>definitivo</strong>. No podrás reabrir la sesión, registrar
                nuevas ventas ni agregar movimientos a esta caja. Si detectas una diferencia, la observación será obligatoria
                antes de cerrar.
            </div>

            @if($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-disc ps-5 space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('cajas.cerrar.store', $sesion) }}"
                  class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
                @csrf

                <div>
                    <x-input-label value="Denominaciones contadas (efectivo físico del cajón)" />
                    <p class="mt-1 text-xs text-gray-500">
                        Conteo ciego: captura únicamente las piezas que tienes enfrente. El sistema calcula el total y la
                        diferencia contra el efectivo esperado al momento de cerrar.
                    </p>

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        @foreach($denominaciones as $den)
                            @php
                                $esMoneda = $den < 10;
                                $clave = (string) $den;
                            @endphp
                            <div class="flex items-center gap-3 rounded-lg border border-gray-200 px-3 py-2">
                                <span class="w-20 text-sm font-semibold text-gray-900">
                                    {{ $den % 100 == 0 ? '$'.number_format($den, 0) : '$'.$den }}
                                    <span class="block text-[10px] font-normal text-gray-400 uppercase">{{ $esMoneda ? 'moneda' : 'billete' }}</span>
                                </span>
                                <input
                                    type="number"
                                    min="0"
                                    max="1000000"
                                    step="1"
                                    value="0"
                                    data-denominacion="{{ $clave }}"
                                    name="denominaciones[{{ $clave }}]"
                                    class="block w-full rounded-lg border-gray-300 text-sm text-right focus:border-gray-900 focus:ring-gray-900"
                                    placeholder="0"
                                >
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3">
                    <span class="text-sm font-semibold text-gray-700">Total contado (billetes y monedas)</span>
                    <span data-total-arqueo class="text-lg font-bold text-gray-900">$0.00</span>
                </div>

                <div>
                    <x-input-label for="observaciones_cierre" value="Observaciones" />
                    <textarea
                        id="observaciones_cierre"
                        name="observaciones_cierre"
                        rows="3"
                        maxlength="2000"
                        placeholder="Notas del cierre (obligatorio si existe diferencia)…"
                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                    >{{ old('observaciones_cierre') }}</textarea>
                    <x-input-error :messages="$errors->get('observaciones_cierre')" class="mt-2" />
                </div>

                <button type="submit" id="btn-cerrar"
                        class="w-full rounded-lg bg-rose-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-rose-700">
                    Cerrar sesión y generar corte
                </button>
            </form>
        </div>
    </div>

    @push('scripts')
    <script>
    (() => {
        const inputs = document.querySelectorAll('[data-denominacion]');
        const totalEl = document.querySelector('[data-total-arqueo]');
        const btn = document.getElementById('btn-cerrar');

        function formatMoney(centavos) {
            const signo = centavos < 0 ? '-' : '';
            const abs = Math.abs(centavos);
            return signo + '$' + (abs / 100).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function recalcular() {
            let total = 0;
            for (const input of inputs) {
                const cantidad = parseInt(input.value || '0', 10);
                if (Number.isNaN(cantidad) || cantidad < 0) continue;
                const den = parseFloat(input.dataset.denominacion);
                total += Math.round(den * 100) * cantidad;
            }
            totalEl.textContent = formatMoney(total);
        }

        inputs.forEach((input) => {
            input.addEventListener('input', recalcular);
            input.addEventListener('change', recalcular);
        });

        btn.addEventListener('click', () => {
            btn.disabled = true;
            btn.textContent = 'Cerrando sesión…';
            btn.closest('form').submit();
        });
    })();
    </script>
    @endpush
</x-app-layout>