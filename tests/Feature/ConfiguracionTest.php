<?php

use App\Models\Configuracion;
use App\Models\User;
use App\Models\Venta;
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

it('configuracion.edit requiere configuracion.ver', function () {
    $user = configUser(['configuracion.ver']);
    $this->actingAs($user)->get(route('configuracion.edit'))->assertOk();

    $sinPermiso = configUser(['ventas.ver']);
    $this->actingAs($sinPermiso)->get(route('configuracion.edit'))->assertForbidden();
});

it('configuracion.update requiere configuracion.editar', function () {
    $user = configUser(['configuracion.ver']);
    $this->actingAs($user)
        ->put(route('configuracion.update'), ['empresa_nombre' => 'X', 'ticket_ancho' => 80])
        ->assertForbidden();
});

it('persiste identidad, base y ancho permitido', function () {
    $user = configUser(['configuracion.ver', 'configuracion.editar']);

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
    $user = configUser(['configuracion.ver', 'configuracion.editar']);

    $this->actingAs($user)
        ->put(route('configuracion.update'), ['empresa_nombre' => 'X', 'ticket_ancho' => 62])
        ->assertSessionHasErrors('ticket_ancho');
});

it('ticket usa el ancho por defecto de configuración', function () {
    $user = configUser(['configuracion.ver', 'configuracion.editar', 'ventas.ver']);
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
    $user = configUser(['configuracion.ver', 'configuracion.editar', 'ventas.ver']);
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
    $user = configUser(['configuracion.ver', 'configuracion.editar', 'ventas.ver']);
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
