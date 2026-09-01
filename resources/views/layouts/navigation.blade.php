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

                @php
                    // Visibilidad de grupos (nunca vacíos; cada hijo conserva su permiso)
                    $puedeItems       = auth()->user()->can('items.ver');
                    $puedeCategorias  = auth()->user()->can('categorias.ver');
                    $puedeUbicaciones = auth()->user()->can('ubicaciones.ver');
                    $grupoInventario  = $puedeItems || $puedeCategorias || $puedeUbicaciones;

                    $puedePOS     = auth()->user()->can('ventas.crear');
                    $puedeVentas  = auth()->user()->can('ventas.ver');
                    $puedeClientes= auth()->user()->can('clientes.ver');
                    $puedeCxc     = auth()->user()->can('cxc.ver');
                    $grupoVentas  = $puedePOS || $puedeVentas || $puedeClientes || $puedeCxc;

                    $puedeCajaVer       = auth()->user()->can('cajas.ver');
                    $puedeCajaConfigurar= auth()->user()->can('cajas.configurar');
                    $grupoCaja          = $puedeCajaVer || $puedeCajaConfigurar;

                    $puedeUsuarios   = auth()->user()->can('usuarios.ver');
                    $puedeConfiguracion = auth()->user()->can('configuracion.ver');
                    $grupoAdmin      = $puedeUsuarios || $puedeConfiguracion;

                    $puedeReportes   = auth()->user()->can('reportes.ver');

                    // Equipos activo en todas las pantallas funcionales de Item
                    // EXCEPTO items.scan, que es exclusivo de "Escanear".
                    $equiposActivo = request()->routeIs(
                        'items.index',
                        'items.show',
                        'items.create',
                        'items.edit',
                        'items.label',
                        'items.revision',
                    );
                @endphp

                {{-- Desktop links --}}
                <div class="hidden sm:flex items-center gap-1 whitespace-nowrap">
                    @can('dashboard.ver')
                        <a href="{{ route('dashboard') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('dashboard') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Dashboard
                        </a>
                    @endcan

                    {{-- Inventario --}}
                    @if($grupoInventario)
                        <div class="relative" x-data="{ navOpen: false }" @click.outside="navOpen = false">
                            <button @click="navOpen = !navOpen"
                                    type="button"
                                    class="inline-flex items-center gap-1 px-3 py-2 text-sm rounded-lg {{ request()->routeIs('items.*', 'categorias.*', 'ubicaciones.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                                Inventario
                                <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': navOpen }" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                            <div x-show="navOpen"
                                 x-transition
                                 style="display: none;"
                                 @click="navOpen = false"
                                 class="absolute left-0 z-50 mt-2 w-52 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                                @if($puedeItems)
                                    <a href="{{ route('items.index') }}" class="block px-4 py-2 text-sm {{ $equiposActivo ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-50' }}">Equipos</a>
                                    <a href="{{ route('items.scan') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('items.scan') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-50' }}">Escanear</a>
                                @endif
                                @if($puedeCategorias)
                                    <a href="{{ route('categorias.index') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('categorias.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-50' }}">Categorías</a>
                                @endif
                                @if($puedeUbicaciones)
                                    <a href="{{ route('ubicaciones.index') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('ubicaciones.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-50' }}">Ubicaciones</a>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- Ventas --}}
                    @if($grupoVentas)
                        <div class="relative" x-data="{ navOpen: false }" @click.outside="navOpen = false">
                            <button @click="navOpen = !navOpen"
                                    type="button"
                                    class="inline-flex items-center gap-1 px-3 py-2 text-sm rounded-lg {{ request()->routeIs('pos.*', 'ventas.*', 'clientes.*', 'cxc.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                                Ventas
                                <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': navOpen }" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                            <div x-show="navOpen"
                                 x-transition
                                 style="display: none;"
                                 @click="navOpen = false"
                                 class="absolute left-0 z-50 mt-2 w-52 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                                @if($puedePOS)
                                    <a href="{{ route('pos.index') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('pos.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-50' }}">Punto de venta</a>
                                @endif
                                @if($puedeVentas)
                                    <a href="{{ route('ventas.index') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('ventas.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-50' }}">Historial de ventas</a>
                                @endif
                                @if($puedeClientes)
                                    <a href="{{ route('clientes.index') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('clientes.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-50' }}">Clientes</a>
                                @endif
                                @if($puedeCxc)
                                    <a href="{{ route('cxc.index') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('cxc.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-50' }}">Cuentas por cobrar</a>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- Caja --}}
                    @if($grupoCaja)
                        <div class="relative" x-data="{ navOpen: false }" @click.outside="navOpen = false">
                            <button @click="navOpen = !navOpen"
                                    type="button"
                                    class="inline-flex items-center gap-1 px-3 py-2 text-sm rounded-lg {{ request()->routeIs('cajas.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                                Caja
                                <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': navOpen }" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                            <div x-show="navOpen"
                                 x-transition
                                 style="display: none;"
                                 @click="navOpen = false"
                                 class="absolute left-0 z-50 mt-2 w-56 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                                @if($puedeCajaVer)
                                    <a href="{{ route('cajas.index') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('cajas.*') && ! request()->routeIs('cajas.gestion*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-50' }}">Operación de caja</a>
                                @endif
                                @if($puedeCajaConfigurar)
                                    <a href="{{ route('cajas.gestion') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('cajas.gestion*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-50' }}">Administración de cajas</a>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- Reportes --}}
                    @if($puedeReportes)
                        <a href="{{ route('reports.index') }}"
                           class="px-3 py-2 text-sm rounded-lg {{ request()->routeIs('reports.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            Reportes
                        </a>
                    @endif

                    {{-- Administración --}}
                    @if($grupoAdmin)
                        <div class="relative" x-data="{ navOpen: false }" @click.outside="navOpen = false">
                            <button @click="navOpen = !navOpen"
                                    type="button"
                                    class="inline-flex items-center gap-1 px-3 py-2 text-sm rounded-lg {{ request()->routeIs('admin.users.*', 'configuracion.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                                Administración
                                <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': navOpen }" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                            <div x-show="navOpen"
                                 x-transition
                                 style="display: none;"
                                 @click="navOpen = false"
                                 class="absolute left-0 z-50 mt-2 w-56 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                                @if($puedeUsuarios)
                                    <a href="{{ route('admin.users.index') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('admin.users.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-50' }}">Usuarios</a>
                                @endif
                                @if($puedeConfiguracion)
                                    <a href="{{ route('configuracion.edit') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('configuracion.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-50' }}">Configuración</a>
                                @endif
                            </div>
                        </div>
                    @endif
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

    {{-- Mobile menu (misma jerarquía en acordeones) --}}
    <div x-cloak x-show="open" x-transition class="sm:hidden border-t border-gray-200 bg-white">
        <div class="px-4 py-3 space-y-1">
            @can('dashboard.ver')
                <a href="{{ route('dashboard') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('dashboard') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Dashboard
                </a>
            @endcan

            {{-- Inventario (acordeón) --}}
            @if($grupoInventario)
                <div x-data="{ mOpen: {{ request()->routeIs('items.*', 'categorias.*', 'ubicaciones.*') ? 'true' : 'false' }} }">
                    <button @click="mOpen = !mOpen" type="button"
                            class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium {{ request()->routeIs('items.*', 'categorias.*', 'ubicaciones.*') ? 'text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">
                        Inventario
                        <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': mOpen }" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                    <div x-show="mOpen" x-transition class="mt-1 space-y-1 ps-3">
                        @if($puedeItems)
                            <a href="{{ route('items.index') }}" class="block rounded-lg px-3 py-2 text-sm {{ $equiposActivo ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">Equipos</a>
                            <a href="{{ route('items.scan') }}" class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('items.scan') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">Escanear</a>
                        @endif
                        @if($puedeCategorias)
                            <a href="{{ route('categorias.index') }}" class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('categorias.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">Categorías</a>
                        @endif
                        @if($puedeUbicaciones)
                            <a href="{{ route('ubicaciones.index') }}" class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('ubicaciones.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">Ubicaciones</a>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Ventas (acordeón) --}}
            @if($grupoVentas)
                <div x-data="{ mOpen: {{ request()->routeIs('pos.*', 'ventas.*', 'clientes.*', 'cxc.*') ? 'true' : 'false' }} }">
                    <button @click="mOpen = !mOpen" type="button"
                            class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium {{ request()->routeIs('pos.*', 'ventas.*', 'clientes.*', 'cxc.*') ? 'text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">
                        Ventas
                        <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': mOpen }" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                    <div x-show="mOpen" x-transition class="mt-1 space-y-1 ps-3">
                        @if($puedePOS)
                            <a href="{{ route('pos.index') }}" class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('pos.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">Punto de venta</a>
                        @endif
                        @if($puedeVentas)
                            <a href="{{ route('ventas.index') }}" class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('ventas.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">Historial de ventas</a>
                        @endif
                        @if($puedeClientes)
                            <a href="{{ route('clientes.index') }}" class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('clientes.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">Clientes</a>
                        @endif
                        @if($puedeCxc)
                            <a href="{{ route('cxc.index') }}" class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('cxc.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">Cuentas por cobrar</a>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Caja (acordeón) --}}
            @if($grupoCaja)
                <div x-data="{ mOpen: {{ request()->routeIs('cajas.*') ? 'true' : 'false' }} }">
                    <button @click="mOpen = !mOpen" type="button"
                            class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium {{ request()->routeIs('cajas.*') ? 'text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">
                        Caja
                        <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': mOpen }" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                    <div x-show="mOpen" x-transition class="mt-1 space-y-1 ps-3">
                        @if($puedeCajaVer)
                            <a href="{{ route('cajas.index') }}" class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('cajas.*') && ! request()->routeIs('cajas.gestion*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">Operación de caja</a>
                        @endif
                        @if($puedeCajaConfigurar)
                            <a href="{{ route('cajas.gestion') }}" class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('cajas.gestion*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">Administración de cajas</a>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Reportes (enlace directo) --}}
            @if($puedeReportes)
                <a href="{{ route('reports.index') }}"
                   class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('reports.*') ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    Reportes
                </a>
            @endif

            {{-- Administración (acordeón) --}}
            @if($grupoAdmin)
                <div x-data="{ mOpen: {{ request()->routeIs('admin.users.*', 'configuracion.*') ? 'true' : 'false' }} }">
                    <button @click="mOpen = !mOpen" type="button"
                            class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium {{ request()->routeIs('admin.users.*', 'configuracion.*') ? 'text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">
                        Administración
                        <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': mOpen }" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                    <div x-show="mOpen" x-transition class="mt-1 space-y-1 ps-3">
                        @if($puedeUsuarios)
                            <a href="{{ route('admin.users.index') }}" class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('admin.users.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">Usuarios</a>
                        @endif
                        @if($puedeConfiguracion)
                            <a href="{{ route('configuracion.edit') }}" class="block rounded-lg px-3 py-2 text-sm {{ request()->routeIs('configuracion.*') ? 'bg-gray-100 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-100' }}">Configuración</a>
                        @endif
                    </div>
                </div>
            @endif

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
