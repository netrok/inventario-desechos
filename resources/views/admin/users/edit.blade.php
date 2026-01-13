<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Editar usuario</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow rounded-lg p-6">
                <form method="POST" action="{{ route('admin.users.update', $user) }}">
                    @method('PUT')
                    @include('admin.users._form', ['user' => $user, 'roles' => $roles])
                    <div class="mt-6 flex gap-2">
                        <button class="px-4 py-2 bg-gray-900 text-white rounded-md">Actualizar</button>
                        <a href="{{ route('admin.users.index') }}" class="px-4 py-2 bg-gray-200 rounded-md">Volver</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
