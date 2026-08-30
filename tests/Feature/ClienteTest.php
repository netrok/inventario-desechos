<?php

use App\Models\Cliente;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'clientes.ver',
        'clientes.crear',
        'clientes.editar',
        'clientes.desactivar',
        'ventas.ver',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function clienteUser(array $permissions = ['clientes.ver']): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

it('genera códigos CLI-XXXXXX consecutivos', function () {
    $a = Cliente::create(['nombre' => 'A']);
    $b = Cliente::create(['nombre' => 'B']);

    expect($a->codigo)->toMatch('/^CLI-\d{6}$/');
    expect($b->codigo)->toMatch('/^CLI-\d{6}$/');
    expect((int) substr($b->codigo, 4))->toBeGreaterThan((int) substr($a->codigo, 4));
});

it('registra un cliente normalizando RFC y email', function () {
    $user = clienteUser(['clientes.ver', 'clientes.crear']);

    $this->actingAs($user)
        ->post(route('clientes.store'), [
            'tipo' => 'PERSONA',
            'nombre' => '  Ana  ',
            'rfc' => '  xaxa010101aaa  ',
            'email' => '  ANA@Example.COM  ',
        ])
        ->assertRedirect(route('clientes.index'));

    $cliente = Cliente::first();
    expect($cliente->nombre)->toBe('Ana');
    expect($cliente->rfc)->toBe('XAXA010101AAA');
    expect($cliente->email)->toBe('ana@example.com');
    expect($cliente->activo)->toBeTrue();
    expect($cliente->codigo)->toMatch('/^CLI-/');
});

it('valida tipo, nombre y email del cliente', function () {
    $user = clienteUser(['clientes.ver', 'clientes.crear']);

    $this->actingAs($user)
        ->post(route('clientes.store'), [
            'tipo' => 'EXTRANJERO',
            'nombre' => '',
            'email' => 'no-es-email',
        ])
        ->assertSessionHasErrors(['tipo', 'nombre', 'email']);

    $this->assertDatabaseCount('clientes', 0);
});

it('permite solo clientes.ver para consultar el catálogo', function () {
    $user = clienteUser(['clientes.ver']);

    $this->actingAs($user)->get(route('clientes.index'))->assertOk();
    $this->actingAs($user)->get(route('clientes.create'))->assertForbidden();
});

it('bloquea el catálogo sin clientes.ver', function () {
    $user = clienteUser([]);

    $this->actingAs($user)->get(route('clientes.index'))->assertForbidden();
});

it('edita un cliente y el código no cambia', function () {
    $user = clienteUser(['clientes.ver', 'clientes.editar']);
    $cliente = Cliente::create(['nombre' => 'Antes', 'tipo' => 'PERSONA']);

    $this->actingAs($user)
        ->put(route('clientes.update', $cliente), [
            'tipo' => 'EMPRESA',
            'nombre' => 'Después S.A.',
        ])
        ->assertRedirect(route('clientes.show', $cliente));

    $cliente->refresh();
    expect($cliente->nombre)->toBe('Después S.A.');
    expect($cliente->tipo)->toBe('EMPRESA');
    expect($cliente->codigo)->toMatch('/^CLI-/');
});

it('desactiva y reactiva sin borrar (clientes.desactivar)', function () {
    $user = clienteUser(['clientes.ver', 'clientes.desactivar']);
    $cliente = Cliente::create(['nombre' => 'X', 'tipo' => 'PERSONA']);

    $this->actingAs($user)->post(route('clientes.toggle', $cliente))
        ->assertRedirect();
    expect($cliente->refresh()->activo)->toBeFalse();

    $this->actingAs($user)->post(route('clientes.toggle', $cliente))
        ->assertRedirect();
    expect($cliente->refresh()->activo)->toBeTrue();

    $this->assertDatabaseCount('clientes', 1);
});

it('no permite desactivar sin clientes.desactivar', function () {
    $user = clienteUser(['clientes.ver', 'clientes.editar']);
    $cliente = Cliente::create(['nombre' => 'X', 'tipo' => 'PERSONA']);

    $this->actingAs($user)->post(route('clientes.toggle', $cliente))->assertForbidden();
    expect($cliente->refresh()->activo)->toBeTrue();
});

it('el scope activos excluye a los inactivos', function () {
    $activo = Cliente::create(['nombre' => 'Activo', 'tipo' => 'PERSONA']);
    $inactivo = Cliente::create(['nombre' => 'Inactivo', 'tipo' => 'PERSONA']);
    $inactivo->update(['activo' => false]);

    expect(Cliente::query()->activos()->pluck('id')->all())->toBe([$activo->id]);
});

it('search devuelve solo activos, limitado y ordenado por nombre', function () {
    $user = clienteUser(['clientes.ver']);
    Cliente::create(['nombre' => 'Zeta', 'tipo' => 'PERSONA', 'rfc' => 'ZZZ']);
    Cliente::create(['nombre' => 'Alfa', 'tipo' => 'PERSONA', 'rfc' => 'AAA']);
    $inactivo = Cliente::create(['nombre' => 'Alfa Inactivo', 'tipo' => 'PERSONA']);
    $inactivo->update(['activo' => false]);

    $this->actingAs($user)
        ->get(route('clientes.search', ['q' => 'Alfa']))
        ->assertOk()
        ->assertJsonCount(1, 'clientes')
        ->assertJsonPath('clientes.0.nombre', 'Alfa');
});

it('la ficha muestra el historial de ventas del cliente', function () {
    $user = clienteUser(['clientes.ver', 'ventas.ver']);
    $cliente = Cliente::create(['nombre' => 'Histórico', 'tipo' => 'PERSONA']);
    $vendedor = User::factory()->create();
    $venta = Venta::create([
        'user_id' => $vendedor->id,
        'cliente_id' => $cliente->id,
        'cliente_codigo' => $cliente->codigo,
        'cliente_nombre' => $cliente->nombre,
        'total' => 100,
        'forma_pago' => 'EFECTIVO',
    ]);

    $this->actingAs($user)
        ->get(route('clientes.show', $cliente))
        ->assertOk()
        ->assertSee($cliente->codigo)
        ->assertSee($venta->folio);
});

it('no hay endpoint de borrado físico para clientes', function () {
    $routes = collect(app('router')->getRoutes()->getRoutesByName());
    expect($routes->has('clientes.destroy'))->toBeFalse();
    expect($routes->has('clientes.delete'))->toBeFalse();
});

it('editar un cliente no altera los snapshots de sus ventas', function () {
    $user = clienteUser(['clientes.ver', 'clientes.editar', 'clientes.desactivar']);
    $cliente = Cliente::create(['nombre' => 'Nombre Original', 'tipo' => 'PERSONA', 'rfc' => 'RFCOLD']);
    $vendedor = User::factory()->create();
    $venta = Venta::create([
        'user_id' => $vendedor->id,
        'cliente_id' => $cliente->id,
        'cliente_codigo' => $cliente->codigo,
        'cliente_nombre' => $cliente->nombre,
        'cliente_rfc' => $cliente->rfc,
        'total' => 50,
        'forma_pago' => 'EFECTIVO',
    ]);

    $this->actingAs($user)->put(route('clientes.update', $cliente), [
        'tipo' => 'PERSONA',
        'nombre' => 'Nombre Nuevo',
        'rfc' => 'RFCNEW',
    ])->assertRedirect();

    $venta->refresh();
    expect($venta->cliente_nombre)->toBe('Nombre Original');
    expect($venta->cliente_rfc)->toBe('RFCOLD');

    $this->actingAs($user)->post(route('clientes.toggle', $cliente));
    $venta->refresh();
    expect($venta->cliente_nombre)->toBe('Nombre Original');
});
