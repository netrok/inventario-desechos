<?php

use App\Models\Cliente;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['ventas.ver', 'ventas.crear', 'clientes.ver'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Movimiento::flushEventListeners();
});

function ticketUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['ventas.ver', 'ventas.crear', 'clientes.ver']);

    return $user;
}

it('el ticket muestra el snapshot del cliente (RFC y teléfono)', function () {
    $user = ticketUser();
    $cliente = Cliente::create([
        'nombre' => 'Cliente Ticket',
        'tipo' => 'PERSONA',
        'rfc' => 'RFCABC',
        'telefono' => '5512345678',
    ]);
    $item = Item::create(['estado' => 'DISPONIBLE', 'precio' => 100.0]);
    $this->session(['pos.cart' => [$item->id], 'pos.cliente_id' => $cliente->id]);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), array_merge([
        'items' => [$item->id],
    ], pagosEfectivo(100.0)));

    $venta = Venta::first();

    $this->actingAs($user)
        ->get(route('ventas.ticket', ['venta' => $venta]))
        ->assertOk()
        ->assertSee('Cliente Ticket')
        ->assertSee('RFCABC')
        ->assertSee('5512345678');
});

it('la reimpresión del ticket es de solo lectura: no muta ventas ni crea movimientos', function () {
    $user = ticketUser();
    $item = Item::create(['estado' => 'DISPONIBLE', 'precio' => 100.0]);
    $cliente = Cliente::create(['nombre' => 'C', 'tipo' => 'PERSONA']);
    $this->session(['pos.cart' => [$item->id], 'pos.cliente_id' => $cliente->id]);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), array_merge([
        'items' => [$item->id],
    ], pagosEfectivo(100.0)));

    $venta = Venta::first();
    $movimientos = Movimiento::where('item_id', $item->id)->count();

    $this->actingAs($user)->get(route('ventas.ticket', ['venta' => $venta]))->assertOk();
    $this->actingAs($user)->get(route('ventas.ticket', ['venta' => $venta, 'width' => 58]))->assertOk();
    $this->actingAs($user)->get(route('ventas.ticket', ['venta' => $venta, 'width' => 80]))->assertOk();

    expect(Movimiento::where('item_id', $item->id)->count())->toBe($movimientos);
    expect($item->refresh()->estado)->toBe('VENDIDO');
});

it('una venta legacy sin snapshot imprime cliente no registrado', function () {
    $user = ticketUser();
    $vendedor = User::factory()->create();
    $venta = Venta::create(['user_id' => $vendedor->id, 'total' => 10, 'forma_pago' => 'EFECTIVO']);

    $this->actingAs($user)
        ->get(route('ventas.ticket', ['venta' => $venta]))
        ->assertOk()
        ->assertSee('No registrado');
});
