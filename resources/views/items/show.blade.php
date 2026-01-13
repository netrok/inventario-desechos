<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">

            {{-- Header --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-semibold text-gray-900">{{ $item->codigo }}</h1>

                        @php
                            $estado = $item->estado ?? 'DISPONIBLE';
                            $badge = match ($estado) {
                                'DISPONIBLE' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                'RESERVADO'  => 'bg-amber-50 text-amber-700 border-amber-200',
                                'REPARACION', 'REPARACIÓN' => 'bg-sky-50 text-sky-700 border-sky-200',
                                'VENDIDO'    => 'bg-gray-100 text-gray-700 border-gray-200',
                                'BAJA'       => 'bg-rose-50 text-rose-700 border-rose-200',
                                default      => 'bg-gray-100 text-gray-700 border-gray-200',
                            };
                        @endphp

                        <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $badge }}">
                            {{ $estado }}
                        </span>
                    </div>

                    <p class="text-sm text-gray-500">
                        {{ $item->marca ?: '—' }}{{ $item->modelo ? ' · '.$item->modelo : '' }}
                        @if($item->serie)
                            <span class="text-gray-400">·</span>
                            <span class="text-gray-600">Serie:</span> {{ $item->serie }}
                        @endif
                    </p>

                    <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                        <span>#{{ $item->id }}</span>
                        <span class="text-gray-300">•</span>
                        <span>
                            Ubicación:
                            <span class="font-medium text-gray-700">{{ $item->ubicacion?->nombre ?? '—' }}</span>
                        </span>
                        <span class="text-gray-300">•</span>
                        <span>
                            Actualizado:
                            <span class="font-medium text-gray-700">{{ optional($item->updated_at)->format('Y-m-d H:i') }}</span>
                        </span>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('items.index') }}"
                       class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm hover:bg-gray-50">
                        ← Volver
                    </a>

                    <a href="{{ route('items.edit', $item) }}"
                       class="inline-flex items-center rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-black">
                        Editar
                    </a>

                    <form method="POST" action="{{ route('items.destroy', $item) }}"
                          onsubmit="return confirm('¿Enviar este item a la papelera?');">
                        @csrf
                        @method('DELETE')
                        <button class="inline-flex items-center rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-100">
                            Eliminar
                        </button>
                    </form>
                </div>
            </div>

            {{-- Flash --}}
            @if(session('success'))
                <div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    {{ session('error') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-disc ps-5 space-y-1">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Content --}}
            <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- Left: Details --}}
                <div class="lg:col-span-2 space-y-6">

                    {{-- Details card --}}
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                            <div>
                                <h2 class="text-sm font-semibold text-gray-900">Detalle</h2>
                                <p class="text-xs text-gray-500 mt-1">Información del equipo y clasificación.</p>
                            </div>
                        </div>

                        <div class="p-6">
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                                    <dt class="text-xs font-medium text-gray-500">Serie</dt>
                                    <dd class="mt-1 text-sm font-semibold text-gray-900">{{ $item->serie ?: '—' }}</dd>
                                </div>

                                <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                                    <dt class="text-xs font-medium text-gray-500">Marca / Modelo</dt>
                                    <dd class="mt-1 text-sm font-semibold text-gray-900">
                                        {{ $item->marca ?: '—' }} {{ $item->modelo ? '· '.$item->modelo : '' }}
                                    </dd>
                                </div>

                                {{-- ✅ FIX: ya no existe categoriaRef ni columna legacy categoria --}}
                                <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                                    <dt class="text-xs font-medium text-gray-500">Categoría</dt>
                                    <dd class="mt-1 text-sm font-semibold text-gray-900">
                                        {{ $item->categoria?->nombre ?? '—' }}
                                    </dd>
                                </div>

                                <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                                    <dt class="text-xs font-medium text-gray-500">Ubicación</dt>
                                    <dd class="mt-1 text-sm font-semibold text-gray-900">{{ $item->ubicacion?->nombre ?: '—' }}</dd>
                                </div>

                                <div class="sm:col-span-2 rounded-xl bg-gray-50 border border-gray-100 p-4">
                                    <dt class="text-xs font-medium text-gray-500">Notas</dt>
                                    <dd class="mt-1 text-sm text-gray-800 whitespace-pre-line">{{ $item->notas ?: '—' }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    {{-- Historial / Movimientos --}}
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                            <div>
                                <h2 class="text-sm font-semibold text-gray-900">Historial</h2>
                                <p class="text-xs text-gray-500 mt-1">Movimientos y cambios registrados.</p>
                            </div>
                        </div>

                        <div class="p-6">
                            @php $movs = $item->movimientos ?? collect(); @endphp

                            @if($movs->isEmpty())
                                <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-600">
                                    Sin movimientos aún.
                                </div>
                            @else
                                <ol class="relative border-s border-gray-200 ms-2 space-y-6">
                                    @foreach($movs as $m)
                                        @php
                                            $dtRaw = $m->fecha ?: $m->created_at;
                                            $dt = $dtRaw ? \Illuminate\Support\Carbon::parse($dtRaw) : null;
                                        @endphp

                                        <li class="ms-6">
                                            <span class="absolute -start-2.5 mt-1 flex h-5 w-5 items-center justify-center rounded-full border border-gray-200 bg-white">
                                                <span class="h-2.5 w-2.5 rounded-full bg-gray-900"></span>
                                            </span>

                                            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                <div class="text-sm font-semibold text-gray-900">
                                                    {{ $m->tipo }}
                                                    <span class="text-gray-300">•</span>
                                                    <span class="text-gray-700">
                                                        {{ $m->de_estado ? ($m->de_estado.' → ') : '' }}
                                                        {{ $m->a_estado ?? '—' }}
                                                    </span>
                                                </div>

                                                <div class="text-xs text-gray-500">
                                                    {{ $dt?->format('Y-m-d H:i') ?? '—' }}
                                                    @if($m->user) <span class="text-gray-300">•</span> {{ $m->user->name }} @endif
                                                </div>
                                            </div>

                                            <div class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs text-gray-600">
                                                <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                                                    <span class="text-gray-500">Ubicación:</span>
                                                    <span class="font-medium text-gray-800">
                                                        {{ $m->deUbicacion?->nombre ?? '—' }} → {{ $m->aUbicacion?->nombre ?? '—' }}
                                                    </span>
                                                </div>

                                                <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                                                    <span class="text-gray-500">Usuario:</span>
                                                    <span class="font-medium text-gray-800">{{ $m->user?->name ?? '—' }}</span>
                                                </div>

                                                <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                                                    <span class="text-gray-500">Notas:</span>
                                                    <span class="font-medium text-gray-800">{{ $m->notas ?? '—' }}</span>
                                                </div>
                                            </div>

                                            @if($m->evidencia_path)
                                                <div class="mt-2">
                                                    <a class="inline-flex items-center text-xs text-gray-700 underline hover:text-gray-900"
                                                       href="{{ asset('storage/'.$m->evidencia_path) }}" target="_blank">
                                                        Ver evidencia
                                                    </a>
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Right: Foto + Quick actions --}}
                <div class="space-y-6">

                    {{-- Foto --}}
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h2 class="text-sm font-semibold text-gray-900">Foto</h2>
                            <p class="text-xs text-gray-500 mt-1">Evidencia visual del item.</p>
                        </div>

                        <div class="p-6">
                            @if(!empty($item->foto_path))
                                <a href="{{ asset('storage/'.$item->foto_path) }}" target="_blank" class="block">
                                    <img
                                        src="{{ asset('storage/'.$item->foto_path) }}"
                                        class="w-full rounded-2xl border object-cover"
                                        style="max-height: 420px;"
                                        alt="foto"
                                        loading="lazy"
                                    >
                                </a>
                            @else
                                <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-10 text-center text-sm text-gray-600">
                                    Sin foto
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Acciones rápidas --}}
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h2 class="text-sm font-semibold text-gray-900">Acciones rápidas</h2>
                            <p class="text-xs text-gray-500 mt-1">Sin entrar a editar.</p>
                        </div>

                        <div class="p-6 space-y-4">
                            {{-- Cambiar estado --}}
                            <form method="POST"
                                  action="{{ route('items.changeEstado', $item->id) }}"
                                  enctype="multipart/form-data"
                                  class="space-y-2">
                                @csrf

                                <label class="block text-xs font-medium text-gray-600">Cambiar estado</label>
                                <select name="estado" class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                                    @foreach(\App\Models\Item::ESTADOS as $e)
                                        <option value="{{ $e }}" @selected($item->estado === $e)>{{ $e }}</option>
                                    @endforeach
                                </select>

                                <input name="notas" placeholder="Notas (opcional)"
                                       class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">

                                <input type="file" name="evidencia" class="w-full text-sm text-gray-700"
                                       accept="image/*,.pdf">

                                <button class="w-full rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">
                                    Guardar estado
                                </button>
                            </form>

                            <div class="h-px bg-gray-100"></div>

                            {{-- Mover ubicación --}}
                            <form method="POST"
                                  action="{{ route('items.moveUbicacion', $item->id) }}"
                                  enctype="multipart/form-data"
                                  class="space-y-2">
                                @csrf

                                <label class="block text-xs font-medium text-gray-600">Mover ubicación</label>
                                <select name="ubicacion_id" class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                                    <option value="">— Sin ubicación —</option>
                                    @foreach($ubicaciones as $u)
                                        <option value="{{ $u->id }}" @selected((string)$item->ubicacion_id === (string)$u->id)>
                                            {{ $u->nombre }}
                                        </option>
                                    @endforeach
                                </select>

                                <input name="notas" placeholder="Notas (opcional)"
                                       class="w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">

                                <input type="file" name="evidencia" class="w-full text-sm text-gray-700"
                                       accept="image/*,.pdf">

                                <button class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium hover:bg-gray-50">
                                    Mover
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h2 class="text-sm font-semibold text-gray-900">Atajo</h2>
                        </div>
                        <div class="p-6">
                            <a href="{{ route('items.trash') }}" class="text-sm text-gray-700 hover:text-gray-900 underline">
                                Ver papelera
                            </a>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
</x-app-layout>
