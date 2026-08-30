<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 space-y-5">

            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">Revisión de artículo devuelto</h1>
                    <p class="mt-1 text-sm text-gray-500">
                        Certificación físico-administrativa previa a reincorporar la mercancía.
                    </p>
                </div>

                <a href="{{ route('items.show', $detalle->item) }}"
                   class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                    ← Volver al item
                </a>
            </div>

            {{-- Flash de errores --}}
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-disc ps-5 space-y-1">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Contexto (no editable): item + devolución concreta --}}
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-900">Contexto de la devolución</h2>
                    <p class="mt-1 text-xs text-gray-500">
                        La revisión se registra sobre la devolución concreta
                        ({{ $detalle->documento->folio }}); cada devolución solo puede revisarse una vez.
                    </p>
                </div>

                <div class="p-6">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                            <dt class="text-xs font-medium text-gray-500">Item</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900">
                                {{ $detalle->item->codigo }}
                                <span class="text-gray-400">· #{{ $detalle->item->id }}</span>
                            </dd>
                        </div>

                        <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                            <dt class="text-xs font-medium text-gray-500">Equipo</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900">
                                {{ $detalle->item->marca ?: '—' }} {{ $detalle->item->modelo ? '· '.$detalle->item->modelo : '' }}
                                @if($detalle->item->serie)
                                    <span class="text-gray-400">· Serie:</span> {{ $detalle->item->serie }}
                                @endif
                            </dd>
                        </div>

                        <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                            <dt class="text-xs font-medium text-gray-500">Categoría</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900">
                                {{ $detalle->item->categoria?->nombre ?? '—' }}
                            </dd>
                        </div>

                        <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                            <dt class="text-xs font-medium text-gray-500">Estado actual</dt>
                            <dd class="mt-1">
                                <span class="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                                    <span class="h-1.5 w-1.5 rounded-full bg-current opacity-70"></span>
                                    {{ $detalle->item->estado }}
                                </span>
                            </dd>
                        </div>

                        <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                            <dt class="text-xs font-medium text-gray-500">Venta original</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900">
                                <a href="{{ route('ventas.show', $detalle->documento->venta) }}" class="hover:underline">
                                    {{ $detalle->documento->venta->folio }}
                                </a>
                            </dd>
                        </div>

                        <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                            <dt class="text-xs font-medium text-gray-500">Cliente</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900">
                                {{ $detalle->documento->venta->cliente_nombre ?: ($detalle->documento->venta->cliente?->nombre ?? '—') }}
                            </dd>
                        </div>

                        <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                            <dt class="text-xs font-medium text-gray-500">Documento de devolución</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900">
                                <a href="{{ route('postventa.show', $detalle->documento) }}" class="hover:underline">
                                    {{ $detalle->documento->folio }}
                                </a>
                                <span class="text-gray-400">· {{ $detalle->documento->created_at->format('d/m/Y H:i') }}</span>
                            </dd>
                        </div>

                        <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                            <dt class="text-xs font-medium text-gray-500">Motivo del cliente</dt>
                            <dd class="mt-1 text-sm text-gray-800 whitespace-pre-line">{{ $detalle->documento->motivo ?: '—' }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            {{-- Decisión de revisión --}}
            <form method="POST" action="{{ route('items.revision.store', $detalle) }}"
                  class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                @csrf

                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-900">Resultado de la revisión</h2>
                    <p class="mt-1 text-xs text-gray-500">Selecciona el destino operativo del artículo.</p>
                </div>

                <div class="p-6 space-y-4">
                    <div class="space-y-2">
                        <label class="flex items-start gap-3 rounded-xl border border-gray-200 p-4 hover:border-gray-300">
                            <input type="radio" name="resultado" value="DISPONIBLE" class="mt-1 h-4 w-4 accent-emerald-600"
                                   @checked(old('resultado') === 'DISPONIBLE')>
                            <span>
                                <span class="block text-sm font-semibold text-gray-900">Apto para venta</span>
                                <span class="block text-xs text-gray-500">
                                    El equipo está en buen estado y se reincorpora al inventario vendible (DISPONIBLE).
                                </span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 rounded-xl border border-gray-200 p-4 hover:border-gray-300">
                            <input type="radio" name="resultado" value="REPARACION" class="mt-1 h-4 w-4 accent-sky-600"
                                   @checked(old('resultado') === 'REPARACION')>
                            <span>
                                <span class="block text-sm font-semibold text-gray-900">Requerirá reparación</span>
                                <span class="block text-xs text-gray-500">
                                    Presenta fallas y se envía a taller (REPARACION) antes de volver a estar vendible.
                                </span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 rounded-xl border border-gray-200 p-4 hover:border-gray-300">
                            <input type="radio" name="resultado" value="BAJA" class="mt-1 h-4 w-4 accent-rose-600"
                                   @checked(old('resultado') === 'BAJA')>
                            <span>
                                <span class="block text-sm font-semibold text-gray-900">No recuperable (baja)</span>
                                <span class="block text-xs text-gray-500">
                                    El equipo no puede volver a venderse y se retira del inventario operativo.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div>
                        <label for="observaciones" class="block text-xs font-medium text-gray-600">
                            Observaciones <span class="text-gray-400">(opcional)</span>
                        </label>
                        <textarea name="observaciones" id="observaciones" rows="3" maxlength="1000"
                                  placeholder="Condición física, hallazgos, firma de responsable…"
                                  class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                            {{ old('observaciones') }}
                        </textarea>
                    </div>

                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                        Esta acción es definitiva: el resultado registrado y el movimiento de trazabilidad no se
                        podrán corregir ni eliminar.
                    </div>

                    <button class="w-full rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-black">
                        Confirmar revisión
                    </button>
                </div>
            </form>

        </div>
    </div>
</x-app-layout>