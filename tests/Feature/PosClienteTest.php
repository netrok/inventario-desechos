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

function posUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['ventas.ver', 'ventas.crear', 'clientes.ver', 'clientes.crear']);

    return $user;
}

function posItem2(float $precio = 100.0): Item
{
    return Item::create(['estado' => 'DISPONIBLE', 'precio' => $precio]);
}

function checkout(array $extra = []): \Illuminate\Testing\TestResponse
{
    $item = posItem2();

    test()->session(['pos.cart' => [$item->id]]);

    $venta = test()->actingAs(posUser())->post(route('pos.checkout'), array_merge([
        'items' => [$item->id],
        'forma_pago' => 'EFECTIVO',
    ], $extra));

    return $venta;
}

it('el checkout exige un cliente seleccionado', function () {
    checkout()->assertSessionHasErrors('cliente_id');
    $this->assertDatabaseCount('ventas', 0);
});

it('rechaza un cliente inexistente', function () {
    $item = posItem2();
    $this->session(['pos.cart' => [$item->id], 'pos.cliente_id' => 9999]);

    $this->actingAs(posUser())
        ->post(route('pos.checkout'), ['items' => [$item->id], 'forma_pago' => 'EFECTIVO'])
        ->assertSessionHasErrors('cliente_id');

    $this->assertDatabaseCount('ventas', 0);
});

it('rechaza un cliente inactivo (aunque esté en sesión)', function () {
    $cliente = Cliente::create(['nombre' => 'Inactivo', 'tipo' => 'PERSONA']);
    $cliente->update(['activo' => false]);

    $item = posItem2();
    $this->session(['pos.cart' => [$item->id], 'pos.cliente_id' => $cliente->id]);

    $this->actingAs(posUser())
        ->post(route('pos.checkout'), ['items' => [$item->id], 'forma_pago' => 'EFECTIVO'])
        ->assertSessionHasErrors('cliente_id');

    $this->assertDatabaseCount('ventas', 0);
});

it('guarda el snapshot del cliente al momento de la venta', function () {
    $cliente = Cliente::create([
        'nombre' => 'Cliente Venta',
        'tipo' => 'PERSONA',
        'rfc' => 'RFC123',
        'telefono' => '555',
        'email' => 'c@x.com',
    ]);
    $item = posItem2();
    $this->session(['pos.cart' => [$item->id], 'pos.cliente_id' => $cliente->id]);

    $this->actingAs(posUser())
        ->post(route('pos.checkout'), ['items' => [$item->id], 'forma_pago' => 'EFECTIVO']);

    $this->assertDatabaseCount('ventas', 1);

    $venta = Venta::first();
    expect($venta->cliente_codigo)->toMatch('/^CLI-/');
    expect($venta->cliente_nombre)->toBe('Cliente Venta');
    expect($venta->cliente_rfc)->toBe('RFC123');
    expect($venta->cliente_telefono)->toBe('555');
    expect($venta->cliente_email)->toBe('c@x.com');
    expect($venta->cliente_tipo)->toBe('PERSONA');
});

it('el checkout limpia el carrito y la selección de cliente', function () {
    $cliente = Cliente::create(['nombre' => 'Cliente', 'tipo' => 'PERSONA']);
    $item = posItem2();
    $this->session(['pos.cart' => [$item->id], 'pos.cliente_id' => $cliente->id]);

    $this->actingAs(posUser())
        ->post(route('pos.checkout'), ['items' => [$item->id], 'forma_pago' => 'EFECTIVO'])
        ->assertRedirect();

    expect(session('pos.cart'))->toBe([]);
    expect(session('pos.cliente_id'))->toBeNull();
});

it('pos.cliente: no acepta un id inactivo o inexistente', function () {
    $inactivo = Cliente::create(['nombre' => 'Inactivo', 'tipo' => 'PERSONA']);
    $inactivo->update(['activo' => false]);

    $this->actingAs(posUser())
        ->post(route('pos.cliente'), ['cliente_id' => $inactivo->id])
        ->assertSessionHasErrors('cliente_id');

    $this->actingAs(posUser())
        ->post(route('pos.cliente'), ['cliente_id' => 9999])
        ->assertSessionHasErrors('cliente_id');
});

it('pos.cliente guarda la selección y limpiar la quita', function () {
    $cliente = Cliente::create(['nombre' => 'Activo', 'tipo' => 'PERSONA']);

    $this->actingAs(posUser())
        ->post(route('pos.cliente'), ['cliente_id' => $cliente->id])
        ->assertRedirect(route('pos.index'));

    expect(session('pos.cliente_id'))->toBe($cliente->id);

    $this->actingAs(posUser())
        ->post(route('pos.cliente.limpiar'))
        ->assertRedirect(route('pos.index'));

    expect(session('pos.cliente_id'))->toBeNull();
});

it('una venta legacy sin cliente se muestra como venta histórica y persiste cliente_id NULL', function () {
    $vendedor = User::factory()->create();
    $venta = Venta::create([
        'user_id' => $vendedor->id,
        'total' => 99,
        'forma_pago' => 'EFECTIVO',
    ]);

    expect($venta->cliente_id)->toBeNull();
    expect($venta->cliente_historico)->toBeNull();
    $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'cliente_id' => null]);

    $user = posUser();
    $this->actingAs($user)->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee('Cliente no registrado');
});

it('la creación del cliente rápido exige clientes.crear', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('ventas.crear'); // POS, pero sin clientes.crear

    $this->actingAs($user)
        ->post(route('clientes.rapida'), ['tipo' => 'PERSONA', 'nombre' => 'X'])
        ->assertForbidden();
});

it('el alta rápida conserva el carrito y deja el cliente seleccionado', function () {
    $item = posItem2();
    $this->session(['pos.cart' => [$item->id]]);

    $this->actingAs(posUser())
        ->post(route('clientes.rapida'), ['tipo' => 'PERSONA', 'nombre' => '  Nuevo  '])
        ->assertRedirect(route('pos.index'));

    // Carrito intacto.
    expect(session('pos.cart'))->toBe([$item->id]);

    // Cliente creado (server-side, activo, normalizado) y seleccionado.
    expect(Cliente::count())->toBe(1);
    $cliente = Cliente::first();
    expect($cliente->nombre)->toBe('Nuevo');
    expect($cliente->activo)->toBeTrue();
    expect(session('pos.cliente_id'))->toBe($cliente->id);
});

it('el alta rápida no acepta campos manipulados ni un tipo inválido', function () {
    $this->actingAs(posUser())
        ->post(route('clientes.rapida'), [
            'tipo' => 'HACKER',
            'nombre' => 'Malo',
            'activo' => 0, // no se respeta: siempre ACTIVO al crear
            'codigo' => 'CLI-999999', // el código lo genera el servidor
        ])
        ->assertSessionHasErrors('tipo');

    // El código nunca lo impone el cliente: se genera por sequence.
    $this->actingAs(posUser())
        ->post(route('clientes.rapida'), [
            'tipo' => 'PERSONA',
            'nombre' => 'Ok',
            'codigo' => 'CLI-999999',
        ]);
    expect(Cliente::latest('id')->first()->codigo)->not->toBe('CLI-999999');
});
