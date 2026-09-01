<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Punto de venta</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Escanea el código del equipo para agregarlo al carrito y registra la venta en un solo paso.
                </p>
            </div>

            @can('ventas.ver')
                <a href="{{ route('ventas.index') }}"
                   class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                    Ver ventas registradas
                </a>
            @endcan
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
                    <ul class="list-disc ps-5 space-y-1">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-5 gap-5">

                {{-- Panel escáner + carrito --}}
                <div class="lg:col-span-3 space-y-5">
                    @can('ventas.crear')
                        <form method="POST" action="{{ route('pos.add') }}" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
                            @csrf
                            <label for="codigo" class="block text-sm font-semibold text-gray-900">Escanea / escribe el código</label>
                            <div class="mt-2 flex gap-2">
                                <input
                                    id="codigo"
                                    name="codigo"
                                    type="text"
                                    value="{{ old('codigo') }}"
                                    placeholder="ITM-000123"
                                    autofocus
                                    autocomplete="off"
                                    class="w-full rounded-lg border-gray-300 text-lg font-semibold tracking-wide focus:border-gray-900 focus:ring-gray-900"
                                >
                                <button type="submit"
                                        class="inline-flex items-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                                    Agregar
                                </button>
                            </div>
                        </form>
                    @else
                        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
                            Modo consulta: tu rol puede ver el punto de venta, pero no registrar ventas.
                        </div>
                    @endcan

                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                        <div class="border-b border-gray-100 px-5 py-4 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900">Carrito</h3>
                            <span class="text-xs text-gray-500">{{ $items->count() }} equipo(s)</span>
                        </div>

                        @if($items->isEmpty())
                            <div class="p-8 text-center text-sm text-gray-500">
                                El carrito está vacío. Escanea un equipo para comenzar.
                            </div>
                        @else
                            <ul class="divide-y divide-gray-100">
                                @foreach($items as $item)
                                    <li class="flex items-center justify-between gap-3 px-5 py-3">
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-semibold text-gray-900">{{ $item->codigo }}</span>
                                                <span class="text-xs px-2 py-0.5 rounded-full {{ $item->estado === 'DISPONIBLE' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                                    {{ $item->estado }}
                                                </span>
                                            </div>
                                            <div class="mt-0.5 text-xs text-gray-500 truncate">
                                                {{ collect([$item->marca, $item->modelo])->filter()->implode(' · ') ?: $item->serie ?: 'Sin descripción' }}
                                            </div>
                                            <div class="text-xs text-gray-400">
                                                {{ $item->categoria?->nombre ?? 'Sin categoría' }}{{ $item->ubicacion ? ' · '.$item->ubicacion->nombre : '' }}
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-3 shrink-0">
                                            <span class="text-sm font-semibold text-gray-900">{{ $item->precio ?? '—' }}</span>

                                            @can('ventas.crear')
                                                <form method="POST" action="{{ route('pos.remove') }}">
                                                    @csrf
                                                    <input type="hidden" name="item_id" value="{{ $item->id }}">
                                                    <button type="submit"
                                                            class="rounded-lg border border-gray-300 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50">
                                                        Quitar
                                                    </button>
                                                </form>
                                            @endcan
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                {{-- Panel confirmación --}}
                <div class="lg:col-span-2 space-y-5">
                    {{-- Panel cliente --}}
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                        <div class="border-b border-gray-100 px-5 py-4">
                            <h3 class="text-sm font-semibold text-gray-900">Cliente</h3>
                            <p class="mt-0.5 text-xs text-gray-500">Requerido para registrar la venta.</p>
                        </div>

                        <div class="px-5 py-4 space-y-3">
                            @if($cliente)
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                    <div class="flex items-center justify-between">
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold text-gray-900">{{ $cliente->nombre }}</div>
                                            <div class="text-xs text-gray-500">{{ $cliente->codigo }} · {{ $cliente->tipo }}</div>
                                            @if($cliente->rfc) <div class="text-xs text-gray-400">RFC {{ $cliente->rfc }}</div> @endif
                                            @if($cliente->telefono) <div class="text-xs text-gray-400">Tel. {{ $cliente->telefono }}</div> @endif
                                        </div>
                                        <form method="POST" action="{{ route('pos.cliente.limpiar') }}">
                                            @csrf
                                            <button type="submit"
                                                    class="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">
                                                Cambiar
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @else
                                <div data-buscar-cliente class="space-y-2">
                                    <input
                                        id="buscar-cliente"
                                        type="search"
                                        data-input-cliente
                                        placeholder="Buscar por nombre, teléfono, RFC o código…"
                                        autocomplete="off"
                                        role="combobox"
                                        aria-expanded="false"
                                        aria-controls="resultados-cliente"
                                        aria-autocomplete="list"
                                        aria-label="Buscar cliente"
                                        class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                                    >
                                    <p data-estado-cliente role="status" aria-live="polite" class="text-xs text-gray-500" style="min-height:1rem"></p>
                                    <div id="resultados-cliente" data-resultados-cliente role="listbox" aria-label="Resultados de cliente" class="max-h-40 overflow-auto space-y-1"></div>

                                    {{-- Selección segura: POST a pos.cliente (sesión). Nunca apunta al endpoint JSON. --}}
                                    <form method="POST" action="{{ route('pos.cliente') }}" data-form-seleccionar>
                                        @csrf
                                        <input type="hidden" name="cliente_id" data-seleccion-cliente>
                                    </form>

                                    <button type="button" data-nuevo-cliente
                                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-800 hover:bg-gray-50">
                                        + Nuevo cliente al vuelo
                                    </button>

                                    <form method="POST" action="{{ route('clientes.rapida') }}" data-rapida-cliente hidden class="space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
                                        @csrf
                                        <div class="grid grid-cols-2 gap-2">
                                            <select name="tipo" class="rounded-lg border-gray-300 text-xs focus:border-gray-900 focus:ring-gray-900">
                                                @foreach(\App\Models\Cliente::TIPOS as $tipo)
                                                    <option value="{{ $tipo }}">{{ $tipo }}</option>
                                                @endforeach
                                            </select>
                                            <input type="text" name="nombre" placeholder="Nombre *" required
                                                   class="rounded-lg border-gray-300 text-xs focus:border-gray-900 focus:ring-gray-900">
                                        </div>
                                        <div class="grid grid-cols-2 gap-2">
                                            <input type="text" name="rfc" placeholder="RFC (opcional)" maxlength="20"
                                                   class="rounded-lg border-gray-300 text-xs uppercase focus:border-gray-900 focus:ring-gray-900">
                                            <input type="text" name="telefono" placeholder="Teléfono (opcional)" maxlength="30"
                                                   class="rounded-lg border-gray-300 text-xs focus:border-gray-900 focus:ring-gray-900">
                                        </div>
                                        <div class="flex gap-2">
                                            <button type="submit"
                                                    class="rounded-lg bg-gray-900 px-3 py-2 text-xs font-semibold text-white hover:bg-black">
                                                Crear y seleccionar
                                            </button>
                                            <button type="button" data-cancelar-rapida
                                                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-800 hover:bg-gray-50">
                                                Cancelar
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            @endif
                        </div>
                    </div>

                    @if($cliente)
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm sticky top-20">
                        <div class="border-b border-gray-100 px-5 py-4">
                            <h3 class="text-sm font-semibold text-gray-900">Confirmar venta</h3>
                        </div>

                        <div class="px-5 py-4 space-y-4">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600">Total (equipos)</span>
                                <span class="text-lg font-bold text-gray-900" data-total-venta="{{ $total }}">{{ $total }}</span>
                            </div>

                            @if(! $sesionCaja)
                                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 space-y-1">
                                    <p class="font-semibold">Debes abrir una caja antes de registrar ventas.</p>
                                    @can('cajas.abrir')
                                        <a href="{{ route('cajas.abrir') }}" class="font-semibold underline">Abrir caja</a>
                                    @else
                                        <p>Solicita a un operador que abra una caja.</p>
                                    @endcan
                                </div>
                            @else
                                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                                    Caja operativa: <strong>{{ $sesionCaja->caja->nombre }}</strong> ·
                                    sesión <strong>{{ $sesionCaja->folio }}</strong>
                                </div>
                            @endif

                            @can('ventas.crear')
                                <form method="POST" action="{{ route('pos.checkout') }}" class="space-y-4">
                                    @csrf

                                    @foreach($items as $item)
                                        <input type="hidden" name="items[]" value="{{ $item->id }}">
                                    @endforeach

                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <label class="block text-xs font-medium text-gray-600">Pagos</label>
                                            <button type="button" data-agregar-pago
                                                    class="text-xs font-semibold text-gray-900 hover:underline">
                                                + Agregar método
                                            </button>
                                        </div>

                                        <div data-pagos-cont class="space-y-2"></div>

                                        <p data-estado-pagos role="status" aria-live="polite"
                                           class="mt-1 text-xs text-gray-500" style="min-height:1rem"></p>

                                        <div data-cambio-cont class="hidden rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm">
                                            <span class="text-sky-800">Cambio a entregar: <strong data-cambio-total>$0.00</strong></span>
                                        </div>
                                    </div>

                                    {{-- CRÉDITO (B15.3): componente de DEUDA separado, nunca en pagos reales --}}
                                    <div data-credito-cont
                                         class="rounded-lg border {{ $creditoInfo['habilitado'] ?? false ? 'border-indigo-200 bg-indigo-50' : 'border-gray-200 bg-gray-50' }} p-3 space-y-2">
                                        <div class="flex items-center justify-between">
                                            <label class="text-xs font-semibold {{ $creditoInfo['habilitado'] ?? false ? 'text-indigo-800' : 'text-gray-500' }}">
                                                Crédito
                                            </label>
                                            @if($creditoInfo && $creditoInfo['habilitado'])
                                                <span class="text-[11px] text-indigo-700">Disponible estimado: <strong>{{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($creditoInfo['disponible_centavos'])) }}</strong></span>
                                            @endif
                                        </div>

                                        @if($creditoInfo && $creditoInfo['habilitado'])
                                            <div class="grid grid-cols-2 gap-2 text-[11px] text-indigo-800">
                                                <div>Límite: {{ \App\Support\Money::formatear(\App\Support\Money::aPrecio($creditoInfo['limite_centavos'])) }}</div>
                                                <div>Plazo: {{ $creditoInfo['dias_credito'] }} día(s)</div>
                                            </div>
                                            <div>
                                                <label for="credito_monto" class="block text-[11px] font-medium text-indigo-700 mb-1">Monto a crédito (0 / parcial / total)</label>
                                                <input
                                                    id="credito_monto"
                                                    name="credito_monto"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    value="{{ old('credito_monto', '') }}"
                                                    placeholder="0.00"
                                                    autocomplete="off"
                                                    data-credito-monto
                                                    class="w-full rounded-lg border-indigo-300 text-sm focus:border-indigo-600 focus:ring-indigo-600"
                                                >
                                            </div>
                                            <p data-estado-credito role="status" aria-live="polite" class="text-[11px] text-indigo-700" style="min-height:1rem"></p>
                                        @else
                                            <p class="text-[11px] text-gray-500">
                                                Este cliente no tiene crédito habilitado. La venta se registra solo con pagos reales.
                                            </p>
                                        @endif
                                    </div>

                                    {{-- Resumen visual (UX; el servidor es autoridad) --}}
                                    <div data-resumen-cont class="hidden rounded-lg border border-gray-200 bg-white p-3 text-xs space-y-1">
                                        <div class="flex justify-between"><span class="text-gray-500">Total</span><span class="font-semibold text-gray-900" data-resumen-total>$0.00</span></div>
                                        <div class="flex justify-between"><span class="text-gray-500">Pagos reales</span><span class="font-semibold text-emerald-700" data-resumen-pagos>$0.00</span></div>
                                        <div class="flex justify-between"><span class="text-gray-500">A crédito</span><span class="font-semibold text-indigo-700" data-resumen-credito>$0.00</span></div>
                                        <div class="flex justify-between border-t border-gray-100 pt-1"><span class="text-gray-500">Pendiente por cubrir</span><span class="font-semibold text-rose-700" data-resumen-pendiente>$0.00</span></div>
                                    </div>

                                    <div>
                                        <label for="notas" class="block text-xs font-medium text-gray-600 mb-1">Notas (opcional)</label>
                                        <textarea
                                            name="notas"
                                            id="notas"
                                            rows="2"
                                            placeholder="Detalles de la venta si aplica"
                                            class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                                        >{{ old('notas') }}</textarea>
                                    </div>

                                    <button type="submit" data-boton-pagar
                                            {{ $items->isEmpty() || ! $sesionCaja ? 'disabled' : '' }}
                                            class="w-full rounded-lg bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed">
                                        Registrar venta
                                    </button>
                                </form>
                            @else
                                <p class="text-sm text-gray-500">
                                    No tienes permiso para registrar ventas. Consulta a un usuario con rol Ventas o Admin.
                                </p>
                            @endcan
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
    (() => {
        const input = document.querySelector('[data-input-cliente]');
        const resultados = document.querySelector('[data-resultados-cliente]');
        const estado = document.querySelector('[data-estado-cliente]');
        const seleccionForm = document.querySelector('[data-form-seleccionar]');
        const seleccionInput = document.querySelector('[data-seleccion-cliente]');
        const rapidaForm = document.querySelector('[data-rapida-cliente]');
        const nuevoClienteBtn = document.querySelector('[data-nuevo-cliente]');
        if (!input) return;

        const url = @json(route('clientes.search'));
        let timeout;
        let aborter = null;

        function setEstado(msg, cls) {
            estado.textContent = msg || '';
            estado.className = 'text-xs ' + (cls || 'text-gray-500');
        }

        function pintarResultados(clientes) {
            resultados.replaceChildren();
            input.setAttribute('aria-expanded', 'false');

            if (!clientes.length) {
                setEstado('Sin resultados');
                return;
            }

            setEstado('');
            input.setAttribute('aria-expanded', 'true');

            for (const c of clientes) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.role = 'option';
                btn.className = 'w-full text-left px-3 py-2 rounded-lg text-sm hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-900';

                const nombre = document.createElement('span');
                nombre.className = 'font-semibold text-gray-900';
                nombre.textContent = c.nombre;

                const meta = document.createElement('span');
                meta.className = 'text-xs text-gray-500';
                meta.textContent = c.codigo + (c.rfc ? ' · ' + c.rfc : '') + (c.telefono ? ' · Tel. ' + c.telefono : '');

                btn.append(nombre);
                btn.appendChild(document.createTextNode(' '));
                btn.append(meta);

                btn.addEventListener('click', () => seleccionar(c.id));
                resultados.appendChild(btn);
            }
        }

        async function buscar(q) {
            if (aborter) aborter.abort();
            aborter = new AbortController();
            setEstado('Buscando…');

            try {
                const res = await fetch(url + '?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' },
                    signal: aborter.signal,
                });
                if (!res.ok) throw new Error('http');
                const data = await res.json();
                pintarResultados(data.clientes || []);
            } catch (err) {
                if (err.name === 'AbortError') return;
                resultados.replaceChildren();
                setEstado('No se pudo buscar. Intenta de nuevo.', 'text-rose-600');
            }
        }

        function disparar() {
            const q = input.value.trim();
            clearTimeout(timeout);
            if (q.length < 2) {
                resultados.replaceChildren();
                setEstado('');
                input.setAttribute('aria-expanded', 'false');
                return;
            }
            timeout = setTimeout(() => buscar(q), 250);
        }

        input.addEventListener('input', disparar);

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const q = input.value.trim();
                if (q.length >= 2) buscar(q);
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                resultados.replaceChildren();
                setEstado('');
                input.setAttribute('aria-expanded', 'false');
                input.blur();
            }
        });

        function seleccionar(id) {
            seleccionInput.value = id;
            resultados.replaceChildren();
            setEstado('Seleccionando cliente…');
            seleccionForm.submit();
        }

        nuevoClienteBtn.addEventListener('click', () => {
            rapidaForm.hidden = !rapidaForm.hidden;
            if (!rapidaForm.hidden) {
                rapidaForm.querySelector('[name="nombre"]').focus();
            } else {
                nuevoClienteBtn.focus();
            }
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('[data-cancelar-rapida]')) return;
            rapidaForm.reset();
            rapidaForm.hidden = true;
            nuevoClienteBtn.focus();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !rapidaForm.hidden) {
                rapidaForm.hidden = true;
                nuevoClienteBtn.focus();
            }
        });

        rapidaForm.addEventListener('submit', () => {
            const submitBtn = rapidaForm.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Creando…';
            }
        });
    })();

        // ============ Pagos combinados (B14) ============
        (() => {
            const FILAS_NETAS = [
                @foreach($metodosPago as $m)
                    @json($m),
                @endforeach
            ];

            const cont = document.querySelector('[data-pagos-cont]');
            const agregarBtn = document.querySelector('[data-agregar-pago]');
            const totalEl = document.querySelector('[data-total-venta]');
            const estadoPagos = document.querySelector('[data-estado-pagos]');
            const cambioCont = document.querySelector('[data-cambio-cont]');
            const cambioTotal = document.querySelector('[data-cambio-total]');
            const botonPagar = document.querySelector('[data-boton-pagar]');
            const creditoInput = document.querySelector('[data-credito-monto]');
            const estadoCredito = document.querySelector('[data-estado-credito]');
            const resumenCont = document.querySelector('[data-resumen-cont]');
            const resumenTotal = document.querySelector('[data-resumen-total]');
            const resumenPagos = document.querySelector('[data-resumen-pagos]');
            const resumenCredito = document.querySelector('[data-resumen-credito]');
            const resumenPendiente = document.querySelector('[data-resumen-pendiente]');
            if (!cont || !totalEl) return;

            const creditHabilitado = {{ ($creditoInfo['habilitado'] ?? false) ? 'true' : 'false' }};
            const totalCentavos = MoneyCentavos(totalEl.textContent);

            function creditoCentavosActivo() {
                if (!creditHabilitado || !creditoInput) return 0;
                return Math.max(0, MoneyCentavos(creditoInput.value || '0'));
            }

            function MoneyCentavos(str) {
                const num = parseFloat(String(str).replace(/[^0-9.\-]/g, ''));
                return Math.round((Number.isNaN(num) ? 0 : num) * 100);
            }

            function fmt(centavos) {
                return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(centavos / 100);
            }

            function crearFila(metodo) {
                const fila = document.createElement('div');
                fila.className = 'rounded-lg border border-gray-200 p-3 space-y-2';

                const cabecera = document.createElement('div');
                cabecera.className = 'flex items-center justify-between';
                const sel = document.createElement('select');
                sel.name = 'pagos[][metodo]';
                sel.className = 'rounded-lg border-gray-300 text-xs focus:border-gray-900 focus:ring-gray-900';
                FILAS_NETAS.forEach((m) => {
                    const op = document.createElement('option');
                    op.value = m; op.textContent = m;
                    sel.appendChild(op);
                });
                sel.value = metodo;
                const quitar = document.createElement('button');
                quitar.type = 'button';
                quitar.textContent = 'Quitar';
                quitar.className = 'text-xs text-rose-600 hover:underline';
                quitar.addEventListener('click', () => { fila.remove(); renumerar(); recalcular(); });
                cabecera.append(sel); cabecera.append(quitar);

                const montoWrap = document.createElement('div');

                const montoLabel = document.createElement('label');
                montoLabel.className = 'mb-1 block text-[11px] font-medium text-gray-500';
                montoLabel.textContent = 'Monto aplicado';

                const montoSel = document.createElement('input');
                montoSel.name = 'pagos[][monto_aplicado]';
                montoSel.type = 'number';
                montoSel.step = '0.01';
                montoSel.min = '0';
                montoSel.placeholder = 'Monto aplicado a la venta';
                montoSel.setAttribute('aria-label', 'Monto aplicado');
                montoSel.required = true;
                montoSel.className = 'w-full rounded-lg border-gray-300 text-xs focus:border-gray-900 focus:ring-gray-900';

                montoWrap.append(montoLabel, montoSel);

                const recibidoCont = document.createElement('div');
                recibidoCont.className = 'hidden';

                const recibidoLabel = document.createElement('label');
                recibidoLabel.className = 'mb-1 block text-[11px] font-medium text-gray-500';
                recibidoLabel.textContent = 'Efectivo recibido';

                const recibido = document.createElement('input');
                recibido.name = 'pagos[][efectivo_recibido]';
                recibido.type = 'number';
                recibido.step = '0.01';
                recibido.min = '0';
                recibido.placeholder = 'Dinero entregado por el cliente';
                recibido.setAttribute('aria-label', 'Efectivo recibido');
                recibido.className = 'w-full rounded-lg border-gray-300 text-xs focus:border-gray-900 focus:ring-gray-900';

                recibidoCont.append(recibidoLabel, recibido);

                const referenciaWrap = document.createElement('div');
                referenciaWrap.className = 'hidden';

                const referenciaLabel = document.createElement('label');
                referenciaLabel.className = 'mb-1 block text-[11px] font-medium text-gray-500';
                referenciaLabel.textContent = 'Referencia';

                const referencia = document.createElement('input');
                referencia.name = 'pagos[][referencia]';
                referencia.type = 'text';
                referencia.maxLength = '100';
                referencia.placeholder = 'Últimos dígitos, autorización o referencia';
                referencia.setAttribute('aria-label', 'Referencia de pago');
                referencia.className = 'w-full rounded-lg border-gray-300 text-xs focus:border-gray-900 focus:ring-gray-900';

                referenciaWrap.append(referenciaLabel, referencia);

                function toggleCampos() {
                    const esEfectivo = sel.value === 'EFECTIVO';
                    recibidoCont.classList.toggle('hidden', !esEfectivo);
                    recibido.required = esEfectivo;
                    referenciaWrap.classList.toggle('hidden', esEfectivo);
                    referencia.required = !esEfectivo && FILAS_NETAS.includes(sel.value);
                    if (!esEfectivo && recibido.value !== '') recibido.value = '';
                    recalcular();
                }

                sel.addEventListener('change', toggleCampos);
                recibido.addEventListener('input', recalcular);
                montoSel.addEventListener('input', recalcular);

                fila.append(cabecera, montoWrap, recibidoCont, referenciaWrap);
                fila._campos = { sel, montoSel, recibido, referencia };
                toggleCampos();
                return fila;
            }

            // Los campos de una fila usan el mismo índice explícito (pagos[N][...])
            // para que PHP agrupe cada fila como un solo pago. Se re-numeran tras
            // agregar o quitar filas para mantener índices contiguos 0..N.
            function renumerar() {
                cont.querySelectorAll(':scope > .rounded-lg.border').forEach((fila, i) => {
                    const c = fila._campos;
                    if (!c) return;
                    c.sel.name = 'pagos[' + i + '][metodo]';
                    c.montoSel.name = 'pagos[' + i + '][monto_aplicado]';
                    c.recibido.name = 'pagos[' + i + '][efectivo_recibido]';
                    c.referencia.name = 'pagos[' + i + '][referencia]';
                });
            }

            // Semilla: primer pago en EFECTIVO solo cuando el crédito está
            // deshabilitado. Con crédito habilitado el usuario decide: puede
            // registrar 100% crédito sin filas de pago, o agregarlas para mixto.
            if (cont.children.length === 0 && !creditHabilitado) {
                cont.appendChild(crearFila('EFECTIVO'));
            }

            agregarBtn.addEventListener('click', () => {
                const usados = Array.from(cont.querySelectorAll('select[name^="pagos["]')).map((s) => s.value);
                const neto = FILAS_NETAS.find((m) => !usados.includes(m)) || FILAS_NETAS[0];
                cont.appendChild(crearFila(neto));
                renumerar();
                recalcular();
            });

            function recalcular() {
                let aplicado = 0;
                let efectivoAplicado = 0;
                let efectivoRecibido = 0;

                cont.querySelectorAll(':scope > .rounded-lg.border').forEach((fila) => {
                    const c = fila._campos;
                    if (!c) return;

                    const montoAplicado = MoneyCentavos(c.montoSel.value);
                    aplicado += montoAplicado;

                    if (c.sel.value === 'EFECTIVO') {
                        efectivoAplicado += montoAplicado;
                        efectivoRecibido += MoneyCentavos(c.recibido.value);
                    }
                });

                const credito = creditoCentavosActivo();
                const faltante = totalCentavos - aplicado - credito;

                // Resumen visual (UX; el servidor es la autoridad).
                if (resumenCont) {
                    resumenTotal && (resumenTotal.textContent = fmt(totalCentavos));
                    resumenPagos && (resumenPagos.textContent = fmt(aplicado));
                    resumenCredito && (resumenCredito.textContent = fmt(credito));
                    resumenPendiente && (resumenPendiente.textContent = fmt(Math.max(0, faltante)));
                    resumenCont.classList.remove('hidden');
                }

                if (creditHabilitado && creditoInput) {
                    if (credito > totalCentavos) {
                        estadoCredito && (estadoCredito.textContent = 'El crédito supera el total.');
                    } else {
                        estadoCredito && (estadoCredito.textContent = '');
                    }
                }

                if (faltante > 0) {
                    estadoPagos.textContent = 'Faltan ' + fmt(faltante) + ' por cubrir del total.';
                    estadoPagos.className = 'mt-1 text-xs text-rose-600';
                    cambioCont.classList.add('hidden');
                    botonPagar && (botonPagar.disabled = true);
                } else if (faltante < 0) {
                    estadoPagos.textContent = 'Los pagos superan el total en ' + fmt(-faltante) + '.';
                    estadoPagos.className = 'mt-1 text-xs text-rose-600';
                    cambioCont.classList.add('hidden');
                    botonPagar && (botonPagar.disabled = true);
                } else {
                    estadoPagos.textContent = '';
                    estadoPagos.className = 'mt-1 text-xs text-gray-500';
                    if (efectivoAplicado > 0 && efectivoRecibido >= efectivoAplicado) {
                        const cambio = efectivoRecibido - efectivoAplicado;
                        cambioTotal.textContent = fmt(cambio);
                        cambioCont.classList.toggle('hidden', cambio <= 0);
                    } else {
                        cambioCont.classList.add('hidden');
                    }
                    const sinSesion = {{ $sesionCaja ? 'false' : 'true' }};
                    botonPagar && (botonPagar.disabled = sinSesion);
                }
            }

            if (creditoInput) {
                creditoInput.addEventListener('input', recalcular);
            }

            renumerar();

            cont.querySelectorAll(':scope > .rounded-lg.border').forEach((fila) => {
                const c = fila._campos;
                if (!c) return;
                c.montoSel.addEventListener('input', recalcular);
                c.recibido.addEventListener('input', recalcular);
                c.referencia.addEventListener('input', recalcular);
                c.sel.addEventListener('change', recalcular);
            });

            recalcular();
        })();
    </script>
    @endpush
</x-app-layout>