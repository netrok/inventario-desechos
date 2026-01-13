<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Usuarios</h2>
            <a href="{{ route('admin.users.create') }}"
               class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-md">
                Nuevo
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 p-3 rounded bg-green-50 text-green-700">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="mb-4 p-3 rounded bg-red-50 text-red-700">{{ session('error') }}</div>
            @endif

            <div class="bg-white shadow rounded-lg p-4">
                <form class="flex gap-2 mb-4" method="GET" action="{{ route('admin.users.index') }}">
                    <input name="q" value="{{ $q }}" placeholder="Buscar por nombre o email..."
                           class="w-full rounded-md border-gray-300" />
                    <button class="px-4 py-2 bg-gray-200 rounded-md">Buscar</button>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left border-b">
                                <th class="py-2">Nombre</th>
                                <th class="py-2">Email</th>
                                <th class="py-2">Roles</th>
                                <th class="py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($users as $u)
                                <tr class="border-b">
                                    <td class="py-2">{{ $u->name }}</td>
                                    <td class="py-2">{{ $u->email }}</td>
                                    <td class="py-2">
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($u->roles as $r)
                                                <span class="px-2 py-0.5 rounded bg-gray-100">{{ $r->name }}</span>
                                            @endforeach
                                            @if ($u->roles->isEmpty())
                                                <span class="text-gray-500">—</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="py-2">
                                        <div class="flex justify-end gap-2">
                                            <a href="{{ route('admin.users.edit', $u) }}"
                                               class="px-3 py-1 bg-blue-50 text-blue-700 rounded">Editar</a>

                                            <form method="POST" action="{{ route('admin.users.destroy', $u) }}"
                                                  onsubmit="return confirm('¿Eliminar usuario?');">
                                                @csrf @method('DELETE')
                                                <button class="px-3 py-1 bg-red-50 text-red-700 rounded">
                                                    Eliminar
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-6 text-center text-gray-500">Sin usuarios</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $users->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
