<x-app-layout title="Configuración">
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 leading-tight">Configuración general</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Identidad que aparece en los tickets e impresión. Sin datos sensibles.
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">

            @if(session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-disc ps-5 space-y-1">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($editable)
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <form method="POST" action="{{ route('configuracion.update') }}" class="p-6 space-y-6">
                        @csrf
                        @method('PUT')

                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Identidad de la empresa</h3>
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="md:col-span-2">
                                    <x-input-label for="empresa_nombre" value="Nombre de la empresa" />
                                    <x-text-input id="empresa_nombre" name="empresa_nombre" class="mt-1 block w-full" value="{{ old('empresa_nombre', $configuracion->empresa_nombre) }}" />
                                    <x-input-error :messages="$errors->get('empresa_nombre')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="empresa_rfc" value="RFC" />
                                    <x-text-input id="empresa_rfc" name="empresa_rfc" class="mt-1 block w-full uppercase" value="{{ old('empresa_rfc', $configuracion->empresa_rfc) }}" maxlength="20" />
                                    <x-input-error :messages="$errors->get('empresa_rfc')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="empresa_telefono" value="Teléfono" />
                                    <x-text-input id="empresa_telefono" name="empresa_telefono" class="mt-1 block w-full" value="{{ old('empresa_telefono', $configuracion->empresa_telefono) }}" maxlength="30" />
                                    <x-input-error :messages="$errors->get('empresa_telefono')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="empresa_email" value="Email" />
                                    <x-text-input id="empresa_email" name="empresa_email" type="email" class="mt-1 block w-full lowercase" value="{{ old('empresa_email', $configuracion->empresa_email) }}" />
                                    <x-input-error :messages="$errors->get('empresa_email')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="empresa_direccion" value="Dirección" />
                                    <x-text-input id="empresa_direccion" name="empresa_direccion" class="mt-1 block w-full" value="{{ old('empresa_direccion', $configuracion->empresa_direccion) }}" />
                                    <x-input-error :messages="$errors->get('empresa_direccion')" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-gray-100 pt-5">
                            <h3 class="text-sm font-semibold text-gray-900">Ticket térmico</h3>
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <x-input-label for="ticket_ancho" value="Ancho del ticket" />
                                    <select id="ticket_ancho" name="ticket_ancho"
                                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">
                                        @foreach(\App\Models\Configuracion::ANCHOS_VALIDOS as $a)
                                            <option value="{{ $a }}" @selected(old('ticket_ancho', $configuracion->ticket_ancho) == $a)>{{ $a }} mm</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('ticket_ancho')" class="mt-2" />
                                </div>
                                <div class="md:col-span-2 flex items-end">
                                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="ticket_autoprint" value="1"
                                               @checked((bool) old('ticket_autoprint', $configuracion->ticket_autoprint))
                                               class="rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                                        Impresión automática al confirmar venta
                                    </label>
                                </div>
                                <div class="md:col-span-3">
                                    <x-input-label for="ticket_pie" value="Pie del ticket" />
                                    <textarea id="ticket_pie" name="ticket_pie" rows="2"
                                              class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900">{{ old('ticket_pie', $configuracion->ticket_pie) }}</textarea>
                                    <x-input-error :messages="$errors->get('ticket_pie')" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-2 border-t border-gray-100 pt-5">
                            <button type="submit"
                                    class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">
                                Guardar configuración
                            </button>
                        </div>
                    </form>
                </div>
            @else
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <div class="space-y-6 p-6">
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            Modo solo lectura: la configuración general solo puede modificarla el rol Admin.
                        </div>

                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Identidad de la empresa</h3>
                            <dl class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="md:col-span-2">
                                    <dt class="text-sm font-medium text-gray-500">Nombre de la empresa</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $configuracion->empresa_nombre ?: '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">RFC</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $configuracion->empresa_rfc ?: '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Teléfono</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $configuracion->empresa_telefono ?: '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Email</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $configuracion->empresa_email ?: '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Dirección</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $configuracion->empresa_direccion ?: '—' }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="border-t border-gray-100 pt-5">
                            <h3 class="text-sm font-semibold text-gray-900">Ticket térmico</h3>
                            <dl class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Ancho del ticket</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $configuracion->ticket_ancho }} mm</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Impresión automática</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $configuracion->ticket_autoprint ? 'Sí' : 'No' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Pie del ticket</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $configuracion->ticket_pie ?: '—' }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
