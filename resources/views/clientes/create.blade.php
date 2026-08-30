<x-app-layout title="Nuevo cliente">
    <div class="py-6">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">

            <div class="flex items-center justify-between gap-3 mb-5">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">Nuevo cliente</h1>
                    <p class="text-sm text-gray-500">Alta de persona o empresa. El código se genera automáticamente.</p>
                </div>

                <a href="{{ route('clientes.index') }}"
                   class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm hover:bg-gray-50">
                    ← Volver
                </a>
            </div>

            @if ($errors->any())
                <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-disc ps-5 space-y-1">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                <form method="POST" action="{{ route('clientes.store') }}" class="p-6">
                    @csrf

                    @include('clientes._form', [
                        'cliente' => null,
                        'tipos' => $tipos,
                    ])

                    <div class="mt-6 flex items-center justify-end gap-2 border-t border-gray-100 pt-5">
                        <a href="{{ route('clientes.index') }}"
                           class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50">
                            Cancelar
                        </a>
                        <button type="submit"
                                class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">
                            Registrar cliente
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
