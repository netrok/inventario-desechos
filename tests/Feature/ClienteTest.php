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
        'ventas.crear',
        'creditos.configurar',
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

function adminCreditoUser(): User
{
    $user = User::factory()->create();
    $role = \Spatie\Permission\Models\Role::findOrCreate('Admin', 'web');
    $user->assignRole($role);
    $user->givePermissionTo('creditos.configurar', 'clientes.ver', 'clientes.crear', 'clientes.editar');

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

// ============ B15.1 — Configuración de crédito por cliente ============

it('B15.1 los defaults del cliente son credito_habilitado=false, limite=0, dias=null', function () {
    $cliente = Cliente::create(['nombre' => 'Sin Crédito', 'tipo' => 'PERSONA'])->refresh();

    expect($cliente->credito_habilitado)->toBeFalse();
    expect((string) $cliente->limite_credito)->toBe('0.00');
    expect($cliente->dias_credito)->toBeNull();
});

it('B15.1 Admin puede crear cliente sin crédito como antes', function () {
    $user = adminCreditoUser();

    $this->actingAs($user)->post(route('clientes.store'), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
    ])->assertRedirect(route('clientes.index'));

    $cliente = Cliente::first();
    expect($cliente->credito_habilitado)->toBeFalse();
    expect((string) $cliente->limite_credito)->toBe('0.00');
    expect($cliente->dias_credito)->toBeNull();
});

it('B15.1 Admin puede crear cliente con crédito habilitado', function () {
    $user = adminCreditoUser();

    $this->actingAs($user)->post(route('clientes.store'), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '1',
        'limite_credito' => '10000',
        'dias_credito' => '30',
    ])->assertRedirect(route('clientes.index'));

    $cliente = Cliente::first();
    expect($cliente->credito_habilitado)->toBeTrue();
    expect((string) $cliente->limite_credito)->toBe('10000.00');
    expect($cliente->dias_credito)->toBe(30);
});

it('B15.1 rechaza crédito habilitado con límite 0', function () {
    $user = adminCreditoUser();

    $this->actingAs($user)->post(route('clientes.store'), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '1',
        'limite_credito' => '0',
        'dias_credito' => '30',
    ])->assertSessionHasErrors('limite_credito');

    $this->assertDatabaseCount('clientes', 0);
});

it('B15.1 rechaza crédito habilitado con límite negativo', function () {
    $user = adminCreditoUser();

    $this->actingAs($user)->post(route('clientes.store'), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '1',
        'limite_credito' => '-5',
        'dias_credito' => '30',
    ])->assertSessionHasErrors('limite_credito');

    $this->assertDatabaseCount('clientes', 0);
});

it('B15.1 rechaza crédito habilitado sin días de crédito', function () {
    $user = adminCreditoUser();

    $this->actingAs($user)->post(route('clientes.store'), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '1',
        'limite_credito' => '10000',
    ])->assertSessionHasErrors('dias_credito');

    $this->assertDatabaseCount('clientes', 0);
});

it('B15.1 rechaza días de crédito en cero', function () {
    $user = adminCreditoUser();

    $this->actingAs($user)->post(route('clientes.store'), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '1',
        'limite_credito' => '10000',
        'dias_credito' => '0',
    ])->assertSessionHasErrors('dias_credito');

    $this->assertDatabaseCount('clientes', 0);
});

it('B15.1 Admin puede modificar la configuración crediticia de un cliente', function () {
    $user = adminCreditoUser();
    $cliente = Cliente::create(['nombre' => 'Ana', 'tipo' => 'PERSONA']);

    $this->actingAs($user)->put(route('clientes.update', $cliente), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '1',
        'limite_credito' => '25000',
        'dias_credito' => '45',
    ])->assertRedirect(route('clientes.show', $cliente));

    $cliente->refresh();
    expect($cliente->credito_habilitado)->toBeTrue();
    expect((string) $cliente->limite_credito)->toBe('25000.00');
    expect($cliente->dias_credito)->toBe(45);
});

it('B15.1 con clientes.editar pero sin creditos.configurar no puede habilitar crédito (HTTP forjado)', function () {
    $user = clienteUser(['clientes.ver', 'clientes.crear', 'clientes.editar']);
    $cliente = Cliente::create(['nombre' => 'Ana', 'tipo' => 'PERSONA']);

    $this->actingAs($user)->put(route('clientes.update', $cliente), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '1',
        'limite_credito' => '50000',
        'dias_credito' => '60',
    ])->assertRedirect(route('clientes.show', $cliente));

    $cliente->refresh();
    expect($cliente->credito_habilitado)->toBeFalse();
    expect((string) $cliente->limite_credito)->toBe('0.00');
    expect($cliente->dias_credito)->toBeNull();
});

it('B15.1 sin creditos.configurar no puede elevar limite_credito por request forjado', function () {
    $user = clienteUser(['clientes.ver', 'clientes.crear', 'clientes.editar']);
    $cliente = Cliente::create([
        'nombre' => 'Ana',
        'tipo' => 'PERSONA',
        'credito_habilitado' => false,
        'limite_credito' => 100,
        'dias_credito' => 15,
    ]);

    $this->actingAs($user)->put(route('clientes.update', $cliente), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'limite_credito' => '999999',
    ])->assertRedirect(route('clientes.show', $cliente));

    $cliente->refresh();
    expect((string) $cliente->limite_credito)->toBe('100.00');
    expect($cliente->dias_credito)->toBe(15);
});

it('B15.1 Ventas creando cliente no puede forjar crédito habilitado', function () {
    $user = clienteUser(['clientes.ver', 'clientes.crear']);

    $this->actingAs($user)->post(route('clientes.store'), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '1',
        'limite_credito' => '50000',
        'dias_credito' => '60',
    ])->assertRedirect(route('clientes.index'));

    $cliente = Cliente::first();
    expect($cliente->credito_habilitado)->toBeFalse();
    expect((string) $cliente->limite_credito)->toBe('0.00');
    expect($cliente->dias_credito)->toBeNull();
});

it('B15.1 Ventas no puede forjar crédito habilitado por alta rápida (rapida)', function () {
    $user = clienteUser(['clientes.ver', 'clientes.crear', 'ventas.crear']);

    $this->actingAs($user)->post(route('clientes.rapida'), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '1',
        'limite_credito' => '50000',
        'dias_credito' => '60',
    ])->assertRedirect(route('pos.index'));

    $cliente = Cliente::first();
    expect($cliente->credito_habilitado)->toBeFalse();
    expect((string) $cliente->limite_credito)->toBe('0.00');
});

it('B15.1 la sección Crédito solo aparece en el formulario para quien tiene creditos.configurar', function () {
    $admin = adminCreditoUser();
    $user = clienteUser(['clientes.ver', 'clientes.crear']);

    $this->actingAs($admin)->get(route('clientes.create'))
        ->assertOk()
        ->assertSee('Habilitar crédito')
        ->assertSee('Límite de crédito')
        ->assertSee('Días de crédito');

    $this->actingAs($user)->get(route('clientes.create'))
        ->assertOk()
        ->assertDontSee('Habilitar crédito')
        ->assertDontSee('Límite de crédito')
        ->assertDontSee('Días de crédito');
});

it('B15.1 regresión: crear/editar/desactivar clientes existentes sigue funcionando', function () {
    $user = clienteUser(['clientes.ver', 'clientes.crear', 'clientes.editar', 'clientes.desactivar']);

    $this->actingAs($user)->post(route('clientes.store'), [
        'tipo' => 'EMPRESA',
        'nombre' => 'Bodega S.A.',
    ])->assertRedirect(route('clientes.index'));

    $cliente = Cliente::first();
    $this->actingAs($user)->put(route('clientes.update', $cliente), [
        'tipo' => 'EMPRESA',
        'nombre' => 'Bodega Nueva S.A.',
    ])->assertRedirect(route('clientes.show', $cliente));

    $this->actingAs($user)->post(route('clientes.toggle', $cliente))->assertRedirect();

    expect($cliente->refresh()->activo)->toBeFalse();
    expect(Cliente::count())->toBe(1);
});

it('B15.1 regresión: el snapshot histórico del cliente en la venta no cambia', function () {
    $user = clienteUser(['clientes.ver', 'clientes.editar']);
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
});

it('B15.1 REV.1 un PUT parcial de Admin no toca la configuración crediticia existente', function () {
    $user = adminCreditoUser();
    $cliente = Cliente::create([
        'nombre' => 'Ana',
        'tipo' => 'PERSONA',
        'credito_habilitado' => true,
        'limite_credito' => 50000,
        'dias_credito' => 30,
    ]);

    $this->actingAs($user)->put(route('clientes.update', $cliente), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana Actualizada',
    ])->assertRedirect(route('clientes.show', $cliente));

    $cliente->refresh();
    expect($cliente->credito_habilitado)->toBeTrue();
    expect((string) $cliente->limite_credito)->toBe('50000.00');
    expect($cliente->dias_credito)->toBe(30);
});

it('B15.1 REV.1 deshabilitar explícitamente crédito conserva límite y días configurados', function () {
    $user = adminCreditoUser();
    $cliente = Cliente::create([
        'nombre' => 'Ana',
        'tipo' => 'PERSONA',
        'credito_habilitado' => true,
        'limite_credito' => 50000,
        'dias_credito' => 30,
    ]);

    $this->actingAs($user)->put(route('clientes.update', $cliente), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '0',
        'limite_credito' => '50000',
        'dias_credito' => '30',
    ])->assertRedirect(route('clientes.show', $cliente));

    $cliente->refresh();
    expect($cliente->credito_habilitado)->toBeFalse();
    expect((string) $cliente->limite_credito)->toBe('50000.00');
    expect($cliente->dias_credito)->toBe(30);
});

it('B15.1 REV.1 user NO Admin con creditos.configurar directo NO modifica crédito', function () {
    $user = clienteUser(['clientes.editar', 'creditos.configurar', 'clientes.ver']);
    $cliente = Cliente::create([
        'nombre' => 'Ana',
        'tipo' => 'PERSONA',
        'credito_habilitado' => false,
        'limite_credito' => 100,
        'dias_credito' => 15,
    ]);

    $this->actingAs($user)->put(route('clientes.update', $cliente), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '1',
        'limite_credito' => '50000',
        'dias_credito' => '60',
    ])->assertRedirect(route('clientes.show', $cliente));

    $cliente->refresh();
    expect($cliente->credito_habilitado)->toBeFalse();
    expect((string) $cliente->limite_credito)->toBe('100.00');
    expect($cliente->dias_credito)->toBe(15);
});

it('B15.1 REV.1 user NO Admin con creditos.configurar directo NO ve la sección Crédito', function () {
    $user = clienteUser(['clientes.editar', 'creditos.configurar', 'clientes.ver']);
    $cliente = Cliente::create(['nombre' => 'Ana', 'tipo' => 'PERSONA']);

    $this->actingAs($user)->get(route('clientes.edit', $cliente))
        ->assertOk()
        ->assertDontSee('Habilitar crédito')
        ->assertDontSee('Límite de crédito')
        ->assertDontSee('Días de crédito');
});

it('B15.1 REV.1 Admin con permiso SÍ continúa pudiendo configurar crédito', function () {
    $user = adminCreditoUser();

    $this->actingAs($user)->post(route('clientes.store'), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '1',
        'limite_credito' => '50000',
        'dias_credito' => '30',
    ])->assertRedirect(route('clientes.index'));

    $cliente = Cliente::first();
    expect($cliente->credito_habilitado)->toBeTrue();
    expect((string) $cliente->limite_credito)->toBe('50000.00');
    expect($cliente->dias_credito)->toBe(30);
});

it('B15.1 REV.2 actualizar solo limite_credito conserva habilitado y días', function () {
    $user = adminCreditoUser();
    $cliente = Cliente::create([
        'nombre' => 'Ana',
        'tipo' => 'PERSONA',
        'credito_habilitado' => true,
        'limite_credito' => 50000,
        'dias_credito' => 30,
    ]);

    $this->actingAs($user)->put(route('clientes.update', $cliente), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'limite_credito' => '75000',
    ])->assertRedirect(route('clientes.show', $cliente));

    $cliente->refresh();
    expect($cliente->credito_habilitado)->toBeTrue();
    expect((string) $cliente->limite_credito)->toBe('75000.00');
    expect($cliente->dias_credito)->toBe(30);
});

it('B15.1 REV.2 actualizar solo dias_credito conserva habilitado y límite', function () {
    $user = adminCreditoUser();
    $cliente = Cliente::create([
        'nombre' => 'Ana',
        'tipo' => 'PERSONA',
        'credito_habilitado' => true,
        'limite_credito' => 50000,
        'dias_credito' => 30,
    ]);

    $this->actingAs($user)->put(route('clientes.update', $cliente), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'dias_credito' => '45',
    ])->assertRedirect(route('clientes.show', $cliente));

    $cliente->refresh();
    expect($cliente->credito_habilitado)->toBeTrue();
    expect((string) $cliente->limite_credito)->toBe('50000.00');
    expect($cliente->dias_credito)->toBe(45);
});

it('B15.1 REV.2 habilitar crédito con límite y días previos funciona', function () {
    $user = adminCreditoUser();
    $cliente = Cliente::create([
        'nombre' => 'Ana',
        'tipo' => 'PERSONA',
        'credito_habilitado' => false,
        'limite_credito' => 50000,
        'dias_credito' => 30,
    ]);

    $this->actingAs($user)->put(route('clientes.update', $cliente), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '1',
    ])->assertRedirect(route('clientes.show', $cliente));

    $cliente->refresh();
    expect($cliente->credito_habilitado)->toBeTrue();
    expect((string) $cliente->limite_credito)->toBe('50000.00');
    expect($cliente->dias_credito)->toBe(30);
});

it('B15.1 REV.2 habilitar crédito sin límite/días válidos previos falla validación', function () {
    $user = adminCreditoUser();
    $cliente = Cliente::create(['nombre' => 'Ana', 'tipo' => 'PERSONA']);

    $this->actingAs($user)->put(route('clientes.update', $cliente), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'credito_habilitado' => '1',
    ])->assertSessionHasErrors('limite_credito');

    $cliente->refresh();
    expect($cliente->credito_habilitado)->toBeFalse();
    expect((string) $cliente->limite_credito)->toBe('0.00');
    expect($cliente->dias_credito)->toBeNull();
});

it('B15.1 REV.2 user NO Admin con permiso directo no modifica nada en parcial', function () {
    $user = clienteUser(['clientes.editar', 'creditos.configurar', 'clientes.ver']);
    $cliente = Cliente::create([
        'nombre' => 'Ana',
        'tipo' => 'PERSONA',
        'credito_habilitado' => true,
        'limite_credito' => 50000,
        'dias_credito' => 30,
    ]);

    $this->actingAs($user)->put(route('clientes.update', $cliente), [
        'tipo' => 'PERSONA',
        'nombre' => 'Ana',
        'limite_credito' => '75000',
    ])->assertRedirect(route('clientes.show', $cliente));

    $cliente->refresh();
    expect($cliente->credito_habilitado)->toBeTrue();
    expect((string) $cliente->limite_credito)->toBe('50000.00');
    expect($cliente->dias_credito)->toBe(30);
});
