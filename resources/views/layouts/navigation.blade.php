<nav x-data="{ open: false }"
     class="sticky top-0 z-40 border-b border-gray-200 bg-white/90 backdrop-blur">

    <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">
            {{-- Left --}}
            <div class="flex items-center gap-4 xl:gap-6 min-w-0">
                <a href="{{ route('dashboard') }}" class="flex flex-shrink-0 items-center gap-2 whitespace-nowrap">
                    <x-application-logo class="h-8 w-auto text-teal-800" />
                    <span class="hidden sm:inline text-sm font-semibold text-gray-900">
                        Inventario ReUse
                    </span>
                </a>

                {{-- Desktop links --}}
                <div class="hidden sm:flex items-center gap-1 whitespace-nowrap">
                    @can('dashboard.ver')
                        <a href="{{ route('dashboard') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('dashboard') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Dashboard
                        </a>
                    @endcan

                    @can('items.ver')
                        <a href="{{ route('items.index') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('items.index', 'items.show', 'items.create', 'items.edit') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Items
                        </a>
                    @endcan

                    @can('items.ver')
                        <a href="{{ route('items.scan') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('items.scan') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Escanear
                        </a>
                    @endcan

                    @can('ventas.crear')
                        <a href="{{ route('pos.index') }}"
                           class="flex-shrink-0 px-3 py-2 text-sm rounded-lg {{ request()->routeIs('pos.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            POS
                        </a>
                    @endcan

                    @can('ventas.ver')
                        <a href="{{ route('ventas.index') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('ventas.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Ventas
                        </a>
                    @endcan

                    @can('cajas.ver')
                        <a href="{{ route('cajas.index') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('cajas.*') && ! request()->routeIs('cajas.gestion*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Caja
                        </a>
                    @endcan

                    @can('cajas.configurar')
                        <a href="{{ route('cajas.gestion') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('cajas.gestion*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Configurar cajas
                        </a>
                    @endcan

                    @can('clientes.ver')
                        <a href="{{ route('clientes.index') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('clientes.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Clientes
                        </a>
                    @endcan

                    @can('reportes.ver')
                        <a href="{{ route('reports.index') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('reports.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Reportes
                        </a>
                    @endcan

                    @can('categorias.ver')
                        <a href="{{ route('categorias.index') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('categorias.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Categorías
                        </a>
                    @endcan

                    @can('ubicaciones.ver')
                        <a href="{{ route('ubicaciones.index') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('ubicaciones.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Ubicaciones
                        </a>
                    @endcan

                    @can('usuarios.ver')
                        <a href="{{ route('admin.users.index') }}"
                           class="flex-shrink-0 px-3 py-2 text-sm rounded-lg {{ request()->routeIs('admin.users.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Usuarios
                        </a>
                    @endcan

                    @can('configuracion.ver')
                        <a href="{{ route('configuracion.edit') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('configuracion.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Configuración
                        </a>
                    @endcan
                </div>
            </div>

            {{-- Right --}}
            <div class="hidden sm:flex items-center gap-3">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button type="button"
                                class="flex-shrink-0 inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 whitespace-nowrap hover:bg-gray-50">
                            <span class="font-medium">{{ Auth::user()->name }}</span>
                            <svg class="h-4 w-4 text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">Perfil</x-dropdown-link>

                        {{-- Logout SIEMPRE POST --}}
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                             onclick="event.preventDefault(); this.closest('form').submit();">
                                Cerrar sesión
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>

                {{-- Botón contextual (con permisos) --}}
                @php
                    $showNew = false;
                    $newUrl = null;
                    $newLabel = '';

                    if (request()->routeIs('items.*') && auth()->user()->can('items.crear')) {
                        $showNew = true; $newUrl = route('items.create'); $newLabel = '+ Nuevo';
                    } elseif (request()->routeIs('categorias.*') && auth()->user()->can('categorias.crear')) {
                        $showNew = true; $newUrl = route('categorias.create'); $newLabel = '+ Nueva';
                    } elseif (request()->routeIs('ubicaciones.*') && auth()->user()->can('ubicaciones.crear')) {
                        $showNew = true; $newUrl = route('ubicaciones.create'); $newLabel = '+ Nueva';
                    } elseif (request()->routeIs('clientes.*') && auth()->user()->can('clientes.crear')) {
                        $showNew = true; $newUrl = route('clientes.create'); $newLabel = '+ Nuevo';
                    }
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
                        type="button"
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
    <div x-cloak x-show="open" x-transition class="sm:hidden border-t border-gray-200 bg-white">
        <div class="px-4 py-3 space-y-1">
            @can('dashboard.ver')
                <a href="{{ route('dashboard') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('dashboard') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Dashboard
                </a>
            @endcan

            @can('items.ver')
                <a href="{{ route('items.index') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('items.index', 'items.show', 'items.create', 'items.edit') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Items
                </a>
            @endcan

            @can('items.ver')
                <a href="{{ route('items.scan') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('items.scan') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Escanear
                </a>
            @endcan

            @can('ventas.crear')
                <a href="{{ route('pos.index') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('pos.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    POS
                </a>
            @endcan

            @can('ventas.ver')
                <a href="{{ route('ventas.index') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('ventas.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Ventas
                </a>
            @endcan

            @can('cajas.ver')
                <a href="{{ route('cajas.index') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('cajas.*') && ! request()->routeIs('cajas.gestion*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Caja
                </a>
            @endcan

            @can('cajas.configurar')
                <a href="{{ route('cajas.gestion') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('cajas.gestion*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Configurar cajas
                </a>
            @endcan

            @can('clientes.ver')
                <a href="{{ route('clientes.index') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('clientes.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Clientes
                </a>
            @endcan

            @can('reportes.ver')
                <a href="{{ route('reports.index') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('reports.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Reportes
                </a>
            @endcan

            @can('categorias.ver')
                <a href="{{ route('categorias.index') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('categorias.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Categorías
                </a>
            @endcan

            @can('ubicaciones.ver')
                <a href="{{ route('ubicaciones.index') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('ubicaciones.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Ubicaciones
                </a>
            @endcan

            @can('usuarios.ver')
                <a href="{{ route('admin.users.index') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('admin.users.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Usuarios
                </a>
            @endcan

            @can('configuracion.ver')
                <a href="{{ route('configuracion.edit') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('configuracion.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Configuración
                </a>
            @endcan

            {{-- Botón contextual (móvil, con permisos) --}}
            @php
                $mobileNewUrl = null;
                $mobileNewLabel = null;

                if (request()->routeIs('items.*') && auth()->user()->can('items.crear')) {
                    $mobileNewUrl = route('items.create'); $mobileNewLabel = '+ Nuevo';
                } elseif (request()->routeIs('categorias.*') && auth()->user()->can('categorias.crear')) {
                    $mobileNewUrl = route('categorias.create'); $mobileNewLabel = '+ Nueva';
                } elseif (request()->routeIs('ubicaciones.*') && auth()->user()->can('ubicaciones.crear')) {
                    $mobileNewUrl = route('ubicaciones.create'); $mobileNewLabel = '+ Nueva';
                } elseif (request()->routeIs('clientes.*') && auth()->user()->can('clientes.crear')) {
                    $mobileNewUrl = route('clientes.create'); $mobileNewLabel = '+ Nuevo';
                }
            @endphp

            @if($mobileNewUrl)
                <div class="pt-2 border-t border-gray-200">
                    <a href="{{ $mobileNewUrl }}"
                       class="block rounded-lg px-3 py-2 text-sm bg-gray-900 text-white hover:bg-black">
                        {{ $mobileNewLabel }}
                    </a>
                </div>
            @endif

            {{-- Logout móvil (POST) --}}
            <div class="pt-2 border-t border-gray-200">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="w-full text-left rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        Cerrar sesión
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>
