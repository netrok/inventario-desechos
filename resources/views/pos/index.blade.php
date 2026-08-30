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
                                <span class="text-lg font-bold text-gray-900">{{ $total }}</span>
                            </div>

                            @can('ventas.crear')
                                <form method="POST" action="{{ route('pos.checkout') }}" class="space-y-4">
                                    @csrf

                                    @foreach($items as $item)
                                        <input type="hidden" name="items[]" value="{{ $item->id }}">
                                    @endforeach

                                    <div>
                                        <label for="forma_pago" class="block text-xs font-medium text-gray-600 mb-1">Forma de pago</label>
                                        <select
                                            name="forma_pago"
                                            id="forma_pago"
                                            class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                                        >
                                            @foreach($formasPago as $f)
                                                <option value="{{ $f }}" @selected(old('forma_pago', 'EFECTIVO') === $f)>{{ $f }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label for="notas" class="block text-xs font-medium text-gray-600 mb-1">Notas (opcional)</label>
                                        <textarea
                                            name="notas"
                                            id="notas"
                                            rows="3"
                                            placeholder="Detalles de la venta si aplica"
                                            class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                                        >{{ old('notas') }}</textarea>
                                    </div>

                                    <button type="submit"
                                            {{ $items->isEmpty() ? 'disabled' : '' }}
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
    </script>
    @endpush
</x-app-layout>