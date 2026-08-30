<?php

use App\Models\Configuracion;
use App\Models\User;
use App\Models\Venta;
use Database\Seeders\RolesAndAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['ventas.ver', 'configuracion.ver', 'configuracion.editar'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function configUser(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function configAdmin(): User
{
    test()->seed(RolesAndAdminSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    return $admin;
}

it('configuracion.edit requiere configuracion.ver', function () {
    $user = configUser(['configuracion.ver']);
    $this->actingAs($user)->get(route('configuracion.edit'))->assertOk();

    $sinPermiso = configUser(['ventas.ver']);
    $this->actingAs($sinPermiso)->get(route('configuracion.edit'))->assertForbidden();
});

it('configuracion.update requiere configuracion.editar y no modifica la BD', function () {
    $user = configUser(['configuracion.ver']);
    Configuracion::obtener()->update(['empresa_nombre' => 'Original SA']);

    $this->actingAs($user)
        ->put(route('configuracion.update'), [
            'empresa_nombre' => 'Hackeada',
            'ticket_ancho' => 80,
        ])
        ->assertForbidden();

    expect(Configuracion::query()->first()->empresa_nombre)->toBe('Original SA');
});

it('un request manual sin ningún permiso de configuración recibe 403 y nada cambia', function () {
    $user = configUser(['ventas.ver']);
    Configuracion::obtener()->update(['empresa_nombre' => 'Original SA']);

    $this->actingAs($user)
        ->put(route('configuracion.update'), [
            'empresa_nombre' => 'Hackeada',
            'ticket_ancho' => 80,
        ])
        ->assertForbidden();

    expect(Configuracion::query()->first()->empresa_nombre)->toBe('Original SA');
    expect(Configuracion::query()->first()->ticket_ancho)->toBe(80);
});

it('el rol Admin por defecto puede editar y guardar configuración', function () {
    $admin = configAdmin();

    $this->actingAs($admin)
        ->put(route('configuracion.update'), [
            'empresa_nombre' => 'ReUse Admin SA',
            'ticket_ancho' => 58,
            'ticket_autoprint' => 1,
        ])
        ->assertRedirect(route('configuracion.edit'));

    expect(Configuracion::query()->first()->empresa_nombre)->toBe('ReUse Admin SA');
    expect((int) Configuracion::query()->first()->ticket_ancho)->toBe(58);
});

it('el rol Auditor ve configuración en modo lectura pero recibe 403 al editar', function () {
    $this->seed(RolesAndAdminSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Configuracion::obtener()->update(['empresa_nombre' => 'Auditoría SA']);

    $auditor = User::factory()->create();
    $auditor->assignRole('Auditor');

    $this->actingAs($auditor)
        ->get(route('configuracion.edit'))
        ->assertOk()
        ->assertSee('Auditoría SA')
        ->assertSee('Modo solo lectura')
        ->assertDontSee('name="empresa_nombre"', false)
        ->assertDontSee('Guardar configuración');

    $this->actingAs($auditor)
        ->put(route('configuracion.update'), [
            'empresa_nombre' => 'Hackeada',
            'ticket_ancho' => 80,
        ])
        ->assertForbidden();

    expect(Configuracion::query()->first()->empresa_nombre)->toBe('Auditoría SA');
});

it('el rol Ventas no puede ver ni editar la configuración', function () {
    $this->seed(RolesAndAdminSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Configuracion::obtener()->update(['empresa_nombre' => 'Original SA']);

    $ventas = User::factory()->create();
    $ventas->assignRole('Ventas');

    $this->actingAs($ventas)->get(route('configuracion.edit'))->assertForbidden();

    $this->actingAs($ventas)
        ->put(route('configuracion.update'), [
            'empresa_nombre' => 'Hackeada',
            'ticket_ancho' => 80,
        ])
        ->assertForbidden();

    expect(Configuracion::query()->first()->empresa_nombre)->toBe('Original SA');
});

it('el rol Almacen no puede ver ni editar la configuración', function () {
    $this->seed(RolesAndAdminSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Configuracion::obtener()->update(['empresa_nombre' => 'Original SA']);

    $almacen = User::factory()->create();
    $almacen->assignRole('Almacen');

    $this->actingAs($almacen)->get(route('configuracion.edit'))->assertForbidden();

    $this->actingAs($almacen)
        ->put(route('configuracion.update'), [
            'empresa_nombre' => 'Hackeada',
            'ticket_ancho' => 80,
        ])
        ->assertForbidden();

    expect(Configuracion::query()->first()->empresa_nombre)->toBe('Original SA');
});

it('un usuario no Admin con configuracion.editar asignado directamente recibe 403 y la BD queda intacta', function () {
    $usuario = configUser(['configuracion.ver', 'configuracion.editar']);
    Configuracion::obtener()->update(['empresa_nombre' => 'Original SA']);

    $this->actingAs($usuario)->get(route('configuracion.edit'))->assertOk();

    $this->actingAs($usuario)
        ->put(route('configuracion.update'), [
            'empresa_nombre' => 'Hackeada',
            'ticket_ancho' => 80,
        ])
        ->assertForbidden();

    expect(Configuracion::query()->first()->empresa_nombre)->toBe('Original SA');
});

it('un usuario no Admin con el permiso de edición ve la vista en solo lectura', function () {
    $usuario = configUser(['configuracion.ver', 'configuracion.editar']);
    Configuracion::obtener()->update(['empresa_nombre' => 'ReUse']);

    $this->actingAs($usuario)
        ->get(route('configuracion.edit'))
        ->assertOk()
        ->assertSee('ReUse')
        ->assertSee('Modo solo lectura')
        ->assertDontSee('name="empresa_nombre"', false)
        ->assertDontSee('name="ticket_ancho"', false)
        ->assertDontSee('Guardar configuración');
});

it('el rol Admin ve el formulario editable con el botón Guardar', function () {
    $admin = configAdmin();
    Configuracion::obtener()->update(['empresa_nombre' => 'ReUse']);

    $this->actingAs($admin)
        ->get(route('configuracion.edit'))
        ->assertOk()
        ->assertSee('name="empresa_nombre"', false)
        ->assertSee('name="ticket_ancho"', false)
        ->assertSee('Guardar configuración')
        ->assertDontSee('Modo solo lectura');
});

it('quien solo tiene configuracion.ver ve los datos de solo lectura sin formulario', function () {
    $user = configUser(['configuracion.ver']);
    Configuracion::obtener()->update(['empresa_nombre' => 'ReUse SA', 'empresa_rfc' => 'XXX010101XXX', 'ticket_ancho' => 58, 'ticket_autoprint' => true, 'ticket_pie' => 'Gracias']);

    $this->actingAs($user)
        ->get(route('configuracion.edit'))
        ->assertOk()
        ->assertSee('ReUse SA')
        ->assertSee('XXX010101XXX')
        ->assertSee('58 mm', false)
        ->assertSee('Sí')
        ->assertSee('Gracias')
        ->assertSee('Modo solo lectura')
        ->assertDontSee('name="empresa_nombre"', false)
        ->assertDontSee('name="ticket_ancho"', false)
        ->assertDontSee('Guardar configuración');
});

it('persiste identidad, base y ancho permitido', function () {
    $user = configAdmin();

    $this->actingAs($user)->put(route('configuracion.update'), [
        'empresa_nombre' => 'ReUse SA',
        'empresa_rfc' => '  xxx010101xxx  ',
        'ticket_ancho' => 58,
        'ticket_autoprint' => 1,
        'ticket_pie' => 'Gracias por su compra',
    ])->assertRedirect(route('configuracion.edit'));

    expect(Configuracion::count())->toBe(1);

    $cfg = Configuracion::obtener();
    expect((int) $cfg->ticket_ancho)->toBe(58);
    expect((bool) $cfg->ticket_autoprint)->toBeTrue();
    expect($cfg->empresa_nombre)->toBe('ReUse SA');
    expect($cfg->empresa_rfc)->toBe('XXX010101XXX');
    expect($cfg->ticket_pie)->toBe('Gracias por su compra');
});

it('rechaza un ancho de ticket no soportado', function () {
    $user = configAdmin();

    $this->actingAs($user)
        ->put(route('configuracion.update'), ['empresa_nombre' => 'X', 'ticket_ancho' => 62])
        ->assertSessionHasErrors('ticket_ancho');
});

it('ticket usa el ancho por defecto de configuración', function () {
    $user = configAdmin();
    $this->actingAs($user)->put(route('configuracion.update'), [
        'empresa_nombre' => 'Empresa Test',
        'ticket_ancho' => 58,
        'ticket_autoprint' => 0,
    ]);

    $vendedor = User::factory()->create();
    $venta = Venta::create(['user_id' => $vendedor->id, 'total' => 10, 'forma_pago' => 'EFECTIVO']);

    $this->actingAs($user)
        ->get(route('ventas.ticket', ['venta' => $venta]))
        ->assertOk()
        ->assertSee('width: 58mm', false)
        ->assertSee('Empresa Test');
});

it('ticket sin autoprint no imprime automático', function () {
    $user = configUser(['ventas.ver', 'configuracion.ver']);
    $vendedor = User::factory()->create();
    $venta = Venta::create(['user_id' => $vendedor->id, 'total' => 10, 'forma_pago' => 'EFECTIVO']);

    $response = $this->actingAs($user)
        ->get(route('ventas.ticket', ['venta' => $venta, 'autoprint' => 1]))
        ->assertOk();

    // Sin autoprint habilitado no se inyecta el script de impresión automática.
    expect($response->getContent())->not->toContain("addEventListener('load'");
});

it('ticket con autoprint habilitado imprime automático', function () {
    $user = configAdmin();
    $this->actingAs($user)->put(route('configuracion.update'), [
        'empresa_nombre' => 'C',
        'ticket_ancho' => 80,
        'ticket_autoprint' => 1,
    ]);

    $vendedor = User::factory()->create();
    $venta = Venta::create(['user_id' => $vendedor->id, 'total' => 10, 'forma_pago' => 'EFECTIVO']);

    $response = $this->actingAs($user)
        ->get(route('ventas.ticket', ['venta' => $venta, 'autoprint' => 1]))
        ->assertOk();

    expect($response->getContent())->toContain("addEventListener('load'");
});

it('sin fila de configuración hay fallback seguro de ticket', function () {
    $user = configUser(['ventas.ver']);
    $vendedor = User::factory()->create();
    $venta = Venta::create(['user_id' => $vendedor->id, 'total' => 5, 'forma_pago' => 'EFECTIVO']);

    $this->actingAs($user)
        ->get(route('ventas.ticket', ['venta' => $venta]))
        ->assertOk()
        ->assertSee('width: 80mm', false);
});

it('la configuración es un singleton garantizado a nivel BD', function () {
    // Crea la fila inicial vía el acceso cacheado.
    Configuracion::obtener();
    expect(Configuracion::count())->toBe(1);

    // Un segundo INSERT directo debe violar el índice único singleton.
    $this->expectException(\Illuminate\Database\QueryException::class);
    Configuracion::query()->create([
        'empresa_nombre' => 'Duplicada',
        'ticket_ancho' => 80,
        'ticket_autoprint' => false,
    ]);
});

it('obtener siempre devuelve la misma fila singleton aunque se llame repetido', function () {
    Configuracion::obtener()->update(['empresa_nombre' => 'A']);

    for ($i = 0; $i < 3; $i++) {
        Configuracion::obtener();
    }

    expect(Configuracion::count())->toBe(1);
    expect(Configuracion::obtener()->empresa_nombre)->toBe('A');
});

it('la BD rechaza un ancho de ticket fuera de 58/80', function () {
    Configuracion::obtener();

    $this->expectException(\Illuminate\Database\QueryException::class);
    Configuracion::query()->where('id', Configuracion::firstOrFail()->id)
        ->update(['ticket_ancho' => 42]);
});

it('visualizar un ticket histórico normal NO autoprinta aunque el autoprint esté activado', function () {
    $user = configAdmin();
    $this->actingAs($user)->put(route('configuracion.update'), [
        'empresa_nombre' => 'C',
        'ticket_ancho' => 58,
        'ticket_autoprint' => 1,
    ]);

    $vendedor = User::factory()->create();
    $venta = Venta::create(['user_id' => $vendedor->id, 'total' => 10, 'forma_pago' => 'EFECTIVO']);

    // Acceso normal al ticket (solo width, sin autoprint) => NO imprime solo.
    $this->actingAs($user)->get(route('ventas.ticket', ['venta' => $venta, 'width' => 58]))
        ->assertOk()
        ->assertSee('width: 58mm', false)
        ->assertDontSee("addEventListener('load'", false);
});
