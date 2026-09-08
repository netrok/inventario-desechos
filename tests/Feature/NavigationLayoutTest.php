<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'dashboard.ver',
        'items.ver',
        'ventas.crear',
        'ventas.ver',
        'clientes.ver',
        'clientes.crear',
        'reportes.ver',
        'categorias.ver',
        'ubicaciones.ver',
        'usuarios.ver',
        'configuracion.ver',
        'cajas.ver',
        'cajas.configurar',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function navLayoutUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        'dashboard.ver',
        'items.ver',
        'ventas.crear',
        'ventas.ver',
        'clientes.ver',
        'clientes.crear',
        'reportes.ver',
        'categorias.ver',
        'ubicaciones.ver',
        'usuarios.ver',
        'configuracion.ver',
        'cajas.ver',
        'cajas.configurar',
    ]);

    return $user;
}

it('la navbar desktop muestra los grupos y sus opciones', function () {
    $this->actingAs(navLayoutUser())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('max-w-screen-2xl')
        ->assertSee('Dashboard')
        ->assertSee('Inventario')
        ->assertSee('Equipos')
        ->assertSee('Escanear')
        ->assertSee('Categorías')
        ->assertSee('Ubicaciones')
        ->assertSee('Ventas')
        ->assertSee('Punto de venta')
        ->assertSee('Historial de ventas')
        ->assertSee('Clientes')
        ->assertSee('Caja')
        ->assertSee('Operación de caja')
        ->assertSee('Administración de cajas')
        ->assertSee('Reportes')
        ->assertSee('Administración')
        ->assertSee('Usuarios')
        ->assertSee('Configuración');
});

it('el menú móvil conserva la misma jerarquía por dominios', function () {
    $this->actingAs(navLayoutUser())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Punto de venta', false)
        ->assertSee('Equipos')
        ->assertSee('Usuarios')
        ->assertSee('Caja')
        ->assertSee('Operación de caja')
        ->assertSee('Administración de cajas');
});

it('el POS conserva el selector de clientes vía @stack("scripts")', function () {
    $this->actingAs(navLayoutUser())
        ->get(route('pos.index'))
        ->assertOk()
        ->assertSee('data-input-cliente')
        ->assertSee('data-form-seleccionar')
        ->assertSee('Seleccionando cliente…');
});

it('sin configuracion.ver el usuario no ve Configuración en navbar ni puede entrar', function () {
    $user = User::factory()->create();
    $user->givePermissionTo([
        'dashboard.ver',
        'items.ver',
        'ventas.crear',
        'ventas.ver',
        'clientes.ver',
        'clientes.crear',
        'reportes.ver',
        'categorias.ver',
        'ubicaciones.ver',
        'usuarios.ver',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('/configuracion');

    $this->actingAs($user)->get(route('configuracion.edit'))->assertForbidden();
});

it('Almacen ve Inventario completo pero NO Administración ni Ventas', function () {
    $role = \Spatie\Permission\Models\Role::findOrCreate('Almacen', 'web');
    $role->syncPermissions([
        'dashboard.ver',
        'items.ver',
        'reportes.ver',
        'categorias.ver',
        'ubicaciones.ver',
    ]);

    $user = User::factory()->create();
    $user->assignRole('Almacen');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Inventario')
        ->assertSee('Equipos')
        ->assertSee('Escanear')
        ->assertSee('Categorías')
        ->assertSee('Ubicaciones')
        ->assertDontSee('Ventas')
        ->assertDontSee('Administración')
        ->assertDontSee('Punto de venta');
});

it('Ventas ve Ventas/POS/Historial/Clientes y Caja cuando tiene cajas.ver', function () {
    $role = \Spatie\Permission\Models\Role::findOrCreate('Ventas', 'web');
    $role->syncPermissions([
        'dashboard.ver',
        'items.ver',
        'ventas.ver',
        'ventas.crear',
        'clientes.ver',
        'cajas.ver',
    ]);

    $user = User::factory()->create();
    $user->assignRole('Ventas');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Ventas')
        ->assertSee('Punto de venta')
        ->assertSee('Historial de ventas')
        ->assertSee('Clientes')
        ->assertSee('Caja')
        ->assertSee('Operación de caja')
        ->assertDontSee('Administración');
});

it('Auditor ve Historial de ventas y Reportes, pero NO Punto de venta ni administración de cajas', function () {
    $role = \Spatie\Permission\Models\Role::findOrCreate('Auditor', 'web');
    $role->syncPermissions([
        'dashboard.ver',
        'items.ver',
        'reportes.ver',
        'ventas.ver',
        'clientes.ver',
        'configuracion.ver',
        'cajas.ver',
    ]);

    $user = User::factory()->create();
    $user->assignRole('Auditor');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Historial de ventas')
        ->assertSee('Reportes')
        ->assertSee('Configuración')
        ->assertSee('Operación de caja')
        ->assertDontSee('Punto de venta')
        ->assertDontSee('Administración de cajas')
        ->assertDontSee('Usuarios');
});

it('un usuario sin hijos de un grupo no ve ese grupo (grupo nunca vacío)', function () {
    // Solo reportes: no debe aparecer Inventario/Ventas/Caja/Administración.
    $user = User::factory()->create();
    $user->givePermissionTo(['dashboard.ver', 'reportes.ver']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Reportes')
        ->assertDontSee('Equipos')
        ->assertDontSee('Punto de venta')
        ->assertDontSee('Operación de caja')
        ->assertDontSee('Usuarios')
        ->assertDontSee('Configuración');
});

it('un grupo con un solo hijo visible no queda vacío', function () {
    // cajas.configurar únicamente: aparece Caja con Administración de cajas
    // pero sin Operación de caja.
    $user = User::factory()->create();
    $user->givePermissionTo(['dashboard.ver', 'cajas.configurar']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Caja')
        ->assertSee('Administración de cajas')
        ->assertDontSee('Operación de caja');
});

it('en items.scan Escanear está activo y Equipos NO, en desktop y móvil', function () {
    $html = $this->actingAs(navLayoutUser())
        ->get(route('items.scan'))
        ->assertOk()
        ->getContent();

    preg_match('/<a href="[^"]*\/items"[^>]*class="([^"]*)"[^>]*>Equipos<\/a>/', $html, $eqDesktop);
    preg_match('/<a href="[^"]*\/items\/scan"[^>]*class="([^"]*)"[^>]*>Escanear<\/a>/', $html, $scDesktop);

    preg_match('/<a href="[^"]*\/items"[^>]*class="([^"]*)"[^>]*>Equipos<\/a>/', $html, $eqMobile);
    preg_match('/<a href="[^"]*\/items\/scan"[^>]*class="([^"]*)"[^>]*>Escanear<\/a>/', $html, $scMobile);

    expect($eqDesktop[1] ?? '')->not->toContain('bg-gray-100 font-medium');
    expect($scDesktop[1] ?? '')->toContain('bg-gray-100 font-medium');
    expect($eqMobile[1] ?? '')->not->toContain('bg-gray-100 font-medium');
    expect($scMobile[1] ?? '')->toContain('bg-gray-100 font-medium');
});

it('en items.index Equipos está activo y Escanear NO, en desktop y móvil', function () {
    $html = $this->actingAs(navLayoutUser())
        ->get(route('items.index'))
        ->assertOk()
        ->getContent();

    preg_match('/<a href="[^"]*\/items"[^>]*class="([^"]*)"[^>]*>Equipos<\/a>/', $html, $eqDesktop);
    preg_match('/<a href="[^"]*\/items\/scan"[^>]*class="([^"]*)"[^>]*>Escanear<\/a>/', $html, $scDesktop);

    preg_match('/<a href="[^"]*\/items"[^>]*class="([^"]*)"[^>]*>Equipos<\/a>/', $html, $eqMobile);
    preg_match('/<a href="[^"]*\/items\/scan"[^>]*class="([^"]*)"[^>]*>Escanear<\/a>/', $html, $scMobile);

    expect($eqDesktop[1] ?? '')->toContain('bg-gray-100 font-medium');
    expect($scDesktop[1] ?? '')->not->toContain('bg-gray-100 font-medium');
    expect($eqMobile[1] ?? '')->toContain('bg-gray-100 font-medium');
    expect($scMobile[1] ?? '')->not->toContain('bg-gray-100 font-medium');
});
