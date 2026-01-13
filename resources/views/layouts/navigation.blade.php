<nav x-data="{ open: false }"
     class="sticky top-0 z-40 border-b border-gray-200 bg-white/90 backdrop-blur">

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">
            {{-- Left --}}
            <div class="flex items-center gap-6">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                    <x-application-logo class="h-8 w-auto fill-current text-gray-900" />
                    <span class="hidden sm:inline text-sm font-semibold text-gray-900">
                        Inventario Desechos
                    </span>
                </a>

                {{-- Desktop links --}}
                <div class="hidden sm:flex items-center gap-1">
                    <a href="{{ route('dashboard') }}"
                       class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('dashboard') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                        Dashboard
                    </a>

                    <a href="{{ route('items.index') }}"
                       class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('items.*') && !request()->routeIs('items.trash') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                        Items
                    </a>

                    <a href="{{ route('items.trash') }}"
                       class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('items.trash') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                        <span class="inline-flex items-center gap-2">
                            Papelera
                            @if(($itemsTrashCount ?? 0) > 0)
                                <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 text-xs font-semibold rounded-full border bg-rose-100 text-rose-700 border-rose-200">
                                    {{ $itemsTrashCount }}
                                </span>
                            @endif
                        </span>
                    </a>

                    <a href="{{ route('categorias.index') }}"
                       class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('categorias.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                        Categorías
                    </a>

                    <a href="{{ route('ubicaciones.index') }}"
                       class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('ubicaciones.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                        Ubicaciones
                    </a>

                    {{-- Admin --}}
                    @role('Admin')
                        <a href="{{ route('admin.users.index') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('admin.users.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Admin / Usuarios
                        </a>
                    @endrole
                </div>
            </div>

            {{-- Right --}}
            <div class="hidden sm:flex items-center gap-3">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <span class="font-medium">{{ Auth::user()->name }}</span>
                            <svg class="h-4 w-4 text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">Perfil</x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                             onclick="event.preventDefault(); this.closest('form').submit();">
                                Cerrar sesión
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>

                {{-- Botón contextual --}}
                @php
                    $showNew =
                        (request()->routeIs('items.*') && !request()->routeIs('items.trash'))
                        || request()->routeIs('categorias.*')
                        || request()->routeIs('ubicaciones.*');

                    $newUrl = match (true) {
                        request()->routeIs('items.*') && !request()->routeIs('items.trash') => route('items.create'),
                        request()->routeIs('categorias.*') => route('categorias.create'),
                        request()->routeIs('ubicaciones.*') => route('ubicaciones.create'),
                        default => null,
                    };

                    $newLabel = match (true) {
                        request()->routeIs('items.*') && !request()->routeIs('items.trash') => '+ Nuevo',
                        request()->routeIs('categorias.*') => '+ Nueva',
                        request()->routeIs('ubicaciones.*') => '+ Nueva',
                        default => '',
                    };
                @endphp

                @if($showNew && $newUrl)
                    <a href="{{ $newUrl }}"
                       class="inline-flex items-center rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-black">
                        {{ $newLabel }}
                    </a>
                @endif
            </div>

            {{-- Mobile button --}}
            <div class="sm:hidden">
                <button @click="open = !open"
                        class="inline-flex items-center justify-center rounded-lg p-2 text-gray-600 hover:bg-gray-100"
                        aria-label="Abrir menú">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path :class="{ 'hidden': open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        <path :class="{ 'hidden': !open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    {{-- Mobile menu --}}
    <div x-cloak
         x-show="open"
         x-transition
         class="sm:hidden border-t border-gray-200 bg-white">
        <div class="px-4 py-3 space-y-1">
            <a href="{{ route('dashboard') }}"
               class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('dashboard') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                Dashboard
            </a>

            <a href="{{ route('items.index') }}"
               class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('items.*') && !request()->routeIs('items.trash') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                Items
            </a>

            <a href="{{ route('items.trash') }}"
               class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('items.trash') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                Papelera @if(($itemsTrashCount ?? 0) > 0) ({{ $itemsTrashCount }}) @endif
            </a>

            <a href="{{ route('categorias.index') }}"
               class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('categorias.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                Categorías
            </a>

            <a href="{{ route('ubicaciones.index') }}"
               class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('ubicaciones.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                Ubicaciones
            </a>

            {{-- Admin --}}
            @role('Admin')
                <a href="{{ route('admin.users.index') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('admin.users.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Admin / Usuarios
                </a>
            @endrole

            {{-- Botón contextual --}}
            @if(request()->routeIs('items.*') && !request()->routeIs('items.trash'))
                <div class="pt-2 border-t border-gray-200">
                    <a href="{{ route('items.create') }}"
                       class="block rounded-lg px-3 py-2 text-sm bg-gray-900 text-white hover:bg-black">
                        + Nuevo
                    </a>
                </div>
            @elseif(request()->routeIs('categorias.*'))
                <div class="pt-2 border-t border-gray-200">
                    <a href="{{ route('categorias.create') }}"
                       class="block rounded-lg px-3 py-2 text-sm bg-gray-900 text-white hover:bg-black">
                        + Nueva
                    </a>
                </div>
            @elseif(request()->routeIs('ubicaciones.*'))
                <div class="pt-2 border-t border-gray-200">
                    <a href="{{ route('ubicaciones.create') }}"
                       class="block rounded-lg px-3 py-2 text-sm bg-gray-900 text-white hover:bg-black">
                        + Nueva
                    </a>
                </div>
            @endif
        </div>
    </div>
</nav>
