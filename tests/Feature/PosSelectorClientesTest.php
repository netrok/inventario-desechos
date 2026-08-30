<?php

use App\Models\Cliente;
use App\Models\Item;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['ventas.ver', 'ventas.crear', 'clientes.ver', 'clientes.crear'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function posClienteUser(array $permissions = ['ventas.ver', 'ventas.crear', 'clientes.ver', 'clientes.crear']): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function posClienteItem(float $precio = 100.0): Item
{
    return Item::create(['estado' => 'DISPONIBLE', 'precio' => $precio]);
}

function posClienteActivo(string $nombre, array $extra = []): Cliente
{
    return Cliente::create(array_merge(['nombre' => $nombre, 'tipo' => 'PERSONA'], $extra));
}

it('el endpoint de búsqueda devuelve los clientes activos esperados', function () {
    posClienteActivo('Juan Pérez', ['telefono' => '3312345678', 'rfc' => 'RFA010101AAA']);
    posClienteActivo('María López');

    $this->actingAs(posClienteUser())
        ->get(route('clientes.search', ['q' => 'Juan']))
        ->assertOk()
        ->assertJsonCount(1, 'clientes')
        ->assertJsonPath('clientes.0.nombre', 'Juan Pérez')
        ->assertJsonPath('clientes.0.tipo', 'PERSONA')
        ->assertJsonPath('clientes.0.telefono', '3312345678');

    // El código CLI exacto se encuentra con solo digitar la parte numérica.
    $cliente = posClienteActivo('Cliente Exacto');
    $numero = substr($cliente->codigo, 4);

    $this->actingAs(posClienteUser())
        ->get(route('clientes.search', ['q' => $numero]))
        ->assertJsonCount(1, 'clientes')
        ->assertJsonPath('clientes.0.codigo', $cliente->codigo);
});

it('los inactivos no aparecen como seleccionables en la búsqueda', function () {
    $inactivo = posClienteActivo('Alfa');
    $inactivo->update(['activo' => false]);
    posClienteActivo('Alfa 2');

    $this->actingAs(posClienteUser())
        ->get(route('clientes.search', ['q' => 'Alfa']))
        ->assertOk()
        ->assertJsonCount(1, 'clientes')
        ->assertJsonPath('clientes.0.nombre', 'Alfa 2');
});

it('un usuario sin clientes.ver no puede buscar clientes', function () {
    $user = posClienteUser(['ventas.ver', 'ventas.crear']);

    $this->actingAs($user)
        ->get(route('clientes.search', ['q' => 'Juan']))
        ->assertForbidden();
});

it('el alta rápida web redirige al POS y no expone JSON crudo', function () {
    $item = posClienteItem();
    $this->session(['pos.cart' => [$item->id]]);

    $response = $this->actingAs(posClienteUser())
        ->withHeaders(['Accept' => 'text/html'])
        ->post(route('clientes.rapida'), ['tipo' => 'PERSONA', 'nombre' => '  María  ']);

    $response->assertRedirect(route('pos.index'));
    expect((string) $response->headers->get('content-type'))->not->toContain('application/json');

    // Carrito conservado y cliente recién creado ya seleccionado (sesión).
    expect(session('pos.cart'))->toBe([$item->id]);
    $cliente = Cliente::first();
    expect($cliente->nombre)->toBe('María');
    expect(session('pos.cliente_id'))->toBe($cliente->id);
});

it('el alta rápida conserva el carrito', function () {
    $item = posClienteItem();
    $this->session(['pos.cart' => [$item->id]]);

    $this->actingAs(posClienteUser())
        ->post(route('clientes.rapida'), ['tipo' => 'PERSONA', 'nombre' => 'Carrito']);

    expect(session('pos.cart'))->toBe([$item->id]);
});

it('el alta rápida deja seleccionado el cliente creado', function () {
    $this->actingAs(posClienteUser())
        ->post(route('clientes.rapida'), ['tipo' => 'PERSONA', 'nombre' => 'Seleccionada']);

    $cliente = Cliente::first();
    expect(session('pos.cliente_id'))->toBe($cliente->id);

    // El POS re-renderiza con el cliente seleccionado y el panel de confirmación.
    $this->get(route('pos.index'))
        ->assertOk()
        ->assertSee($cliente->nombre)
        ->assertSee('Confirmar venta');
});

it('el cliente seleccionado persiste al agregar un Item', function () {
    $cliente = posClienteActivo('Juan Pérez');
    $this->session(['pos.cliente_id' => $cliente->id]);
    $item = posClienteItem();

    $this->actingAs(posClienteUser())
        ->post(route('pos.add'), ['codigo' => $item->codigo])
        ->assertRedirect(route('pos.index'));

    expect(session('pos.cliente_id'))->toBe($cliente->id);

    $this->get(route('pos.index'))->assertOk()->assertSee($cliente->nombre);
});

it('el cliente seleccionado persiste al quitar un Item', function () {
    $cliente = posClienteActivo('Juan Pérez');
    $a = posClienteItem();
    $b = posClienteItem();
    $this->session(['pos.cliente_id' => $cliente->id, 'pos.cart' => [$a->id, $b->id]]);

    $this->actingAs(posClienteUser())
        ->post(route('pos.remove'), ['item_id' => $a->id])
        ->assertRedirect(route('pos.index'));

    expect(session('pos.cliente_id'))->toBe($cliente->id);
    expect(session('pos.cart'))->toBe([$b->id]);
});

it('un checkout fallido conserva carrito y cliente', function () {
    $cliente = posClienteActivo('Juan Pérez');
    $item = posClienteItem();
    $this->session(['pos.cliente_id' => $cliente->id, 'pos.cart' => [$item->id]]);

    $this->actingAs(posClienteUser())
        ->post(route('pos.checkout'), ['items' => [], 'forma_pago' => 'EFECTIVO'])
        ->assertSessionHasErrors('items');

    expect(session('pos.cliente_id'))->toBe($cliente->id);
    expect(session('pos.cart'))->toBe([$item->id]);
    $this->assertDatabaseCount('ventas', 0);
});

it('el checkout exitoso limpia carrito y cliente seleccionado', function () {
    $cliente = posClienteActivo('Juan Pérez');
    $item = posClienteItem();
    $this->session(['pos.cliente_id' => $cliente->id, 'pos.cart' => [$item->id]]);

    $user = posClienteUser();
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), array_merge([
            'items' => [$item->id],
        ], pagosEfectivo(100.0)))
        ->assertRedirect();

    expect(session('pos.cliente_id'))->toBeNull();
    expect(session('pos.cart'))->toBe([]);

    // La siguiente venta no arrastra al cliente de la anterior.
    $this->get(route('pos.index'))
        ->assertOk()
        ->assertSee('data-input-cliente');
});

it('el checkout usa el cliente seleccionado correcto', function () {
    $a = posClienteActivo('Cliente A');
    $b = posClienteActivo('Cliente B');
    $item = posClienteItem();
    $this->session(['pos.cliente_id' => $b->id, 'pos.cart' => [$item->id]]);

    $user = posClienteUser();
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), array_merge([
            'items' => [$item->id],
        ], pagosEfectivo(100.0)));

    $this->assertDatabaseCount('ventas', 1);
    expect(Venta::first()->cliente_id)->toBe($b->id);
    expect(Venta::first()->cliente_nombre)->toBe('Cliente B');
});

it('manipular el cliente_id sigue siendo rechazado (inactivo o inexistente)', function () {
    $inactivo = posClienteActivo('Inactivo');
    $inactivo->update(['activo' => false]);

    $this->actingAs(posClienteUser())
        ->post(route('pos.cliente'), ['cliente_id' => $inactivo->id])
        ->assertSessionHasErrors('cliente_id');

    $this->actingAs(posClienteUser())
        ->post(route('pos.cliente'), ['cliente_id' => 999999])
        ->assertSessionHasErrors('cliente_id');

    expect(session('pos.cliente_id'))->toBeNull();
});

it('el flujo normal del POS renderiza la UI de búsqueda de clientes', function () {
    $this->actingAs(posClienteUser())
        ->get(route('pos.index'))
        ->assertOk()
        ->assertSee('data-input-cliente')
        ->assertSee('data-nuevo-cliente')
        ->assertSee('Buscar por nombre, teléfono, RFC o código');
});

it('ningún link o form del selector apunta al JSON con target=_blank', function () {
    // Sin cliente seleccionado: la UI de búsqueda no debe apuntar al JSON.
    $this->actingAs(posClienteUser())
        ->get(route('pos.index'))
        ->assertOk()
        ->tap(function ($response) {
            $html = $response->getContent();
            expect(preg_match('/<form\b[^>]*action="[^"]*clientes\/search/i', $html))->toBe(0);
            expect(preg_match('/<a\b[^>]*href="[^"]*clientes\/search/i', $html))->toBe(0);
            expect(preg_match('/target="_blank"/i', $html))->toBe(0);
        });

    // Con cliente seleccionado: tampoco debe existir un enlace al JSON.
    $cliente = posClienteActivo('Juan Pérez');
    $this->session(['pos.cliente_id' => $cliente->id]);
    $this->actingAs(posClienteUser())
        ->get(route('pos.index'))
        ->assertOk()
        ->tap(function ($response) {
            $html = $response->getContent();
            expect(preg_match('/<form\b[^>]*action="[^"]*clientes\/search/i', $html))->toBe(0);
            expect(preg_match('/<a\b[^>]*href="[^"]*clientes\/search/i', $html))->toBe(0);
            expect(preg_match('/target="_blank"/i', $html))->toBe(0);
        });
});
