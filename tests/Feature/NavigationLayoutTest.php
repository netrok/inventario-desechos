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
    ]);

    return $user;
}

it('la navbar desktop usa etiquetas cortas en una sola línea', function () {
    $this->actingAs(navLayoutUser())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('max-w-screen-2xl')
        ->assertSee('Dashboard')
        ->assertSee('Items')
        ->assertSee('Escanear')
        ->assertSee('POS')
        ->assertSee('Ventas')
        ->assertSee('Clientes')
        ->assertSee('Reportes')
        ->assertSee('Categorías')
        ->assertSee('Ubicaciones')
        ->assertSee('Usuarios')
        ->assertSee('Configuración')
        ->assertSee('Caja')
        ->assertDontSee('Punto de venta')
        ->assertDontSee('Admin / Usuarios');
});

it('el menú móvil conserva las etiquetas y el punto de venta', function () {
    $this->actingAs(navLayoutUser())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('POS', false)
        ->assertSee('Usuarios')
        ->assertSee('Caja');
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
