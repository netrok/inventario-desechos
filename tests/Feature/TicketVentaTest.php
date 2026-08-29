<?php

use App\Models\Item;
use App\Models\Movimiento;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Database\Seeders\RolesAndAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndAdminSeeder::class);
});

function ticketVendedor(): User
{
    return User::factory()->create(['name' => 'Vendedora Ana']);
}

function ticketVenta(array $precios = [1234.5]): Venta
{
    $user = ticketVendedor();
    $venta = Venta::create([
        'user_id' => $user->id,
        'total' => array_sum($precios),
        'forma_pago' => 'EFECTIVO',
    ]);

    foreach ($precios as $precio) {
        $item = Item::create(['estado' => 'VENDIDO', 'precio' => $precio]);
        VentaDetalle::create([
            'venta_id' => $venta->id,
            'item_id' => $item->id,
            'precio' => $precio,
        ]);
    }

    return $venta;
}

it('muestra el ticket 80mm por defecto y con width=80', function () {
    $venta = ticketVenta([100.0]);
    $user = User::factory()->create()->assignRole('Ventas');

    $this->actingAs($user)->get(route('ventas.ticket', $venta))
        ->assertOk()
        ->assertSee('size: 80mm auto');

    $this->actingAs($user)->get(route('ventas.ticket', ['venta' => $venta, 'width' => 80]))
        ->assertOk()
        ->assertSee('size: 80mm auto')
        ->assertDontSee('size: 58mm auto');
});

it('muestra el ticket 58mm cuando se solicita width=58', function () {
    $venta = ticketVenta([100.0]);
    $user = User::factory()->create()->assignRole('Ventas');

    $this->actingAs($user)->get(route('ventas.ticket', ['venta' => $venta, 'width' => 58]))
        ->assertOk()
        ->assertSee('size: 58mm auto')
        ->assertDontSee('size: 80mm auto');
});

it('acepta cualquier width desconocido como 80mm', function () {
    $venta = ticketVenta([100.0]);
    $user = User::factory()->create()->assignRole('Ventas');

    $this->actingAs($user)->get(route('ventas.ticket', ['venta' => $venta, 'width' => 999]))
        ->assertOk()
        ->assertSee('size: 80mm auto')
        ->assertDontSee('size: 999mm');
});

it('incluye folio, vendedor, forma de pago, codigos, serie y notas del ticket', function () {
    $venta = ticketVenta();
    $item = $venta->detalles->first()->item;
    $item->update(['marca' => 'Dell', 'modelo' => 'Latitude', 'serie' => 'SN-987']);
    $venta->update(['forma_pago' => 'TARJETA', 'notas' => 'Entrega en oficinas']);

    $user = User::factory()->create()->assignRole('Ventas');

    $this->actingAs($user)->get(route('ventas.ticket', $venta))
        ->assertOk()
        ->assertSee($venta->folio)
        ->assertSee('Vendedora Ana')
        ->assertSee('TARJETA')
        ->assertSee($item->codigo)
        ->assertSee('SN-987')
        ->assertSee('Dell')
        ->assertSee('Latitude')
        ->assertSee('Entrega en oficinas')
        ->assertSee('Gracias por su compra');
});

it('usa el precio historico de VentaDetalle aunque el precio actual del Item cambie', function () {
    $venta = ticketVenta([100.0]);
    $item = $venta->detalles->first()->item;

    // El precio del Item cambia DESPUÉS de vendido: el ticket NO debe reflejarlo.
    $item->update(['precio' => 999.0]);

    $user = User::factory()->create()->assignRole('Ventas');

    $this->actingAs($user)->get(route('ventas.ticket', $venta))
        ->assertOk()
        ->assertSee('100.00')
        ->assertDontSee('999.00');
});

it('el ticket es solo lectura: no muta los registros ni crea movimientos', function () {
    $venta = ticketVenta([100.0]);
    $item = $venta->detalles->first()->item;

    $usuarioAntes = $venta->user_id;
    $ventaUpdatedAntes = (string) $venta->fresh()->updated_at;
    $itemUpdatedAntes = (string) $item->fresh()->updated_at;

    $conteosAntes = [
        'ventas' => Venta::count(),
        'detalles' => VentaDetalle::count(),
        'movimientos' => Movimiento::count(),
    ];

    $user = User::factory()->create()->assignRole('Ventas');
    $this->actingAs($user)->get(route('ventas.ticket', $venta))->assertOk();

    $conteosDespues = [
        'ventas' => Venta::count(),
        'detalles' => VentaDetalle::count(),
        'movimientos' => Movimiento::count(),
    ];

    expect($conteosDespues)->toBe($conteosAntes);
    expect((string) $venta->fresh()->updated_at)->toBe($ventaUpdatedAntes);
    expect((string) $item->fresh()->updated_at)->toBe($itemUpdatedAntes);
    expect($venta->fresh()->user_id)->toBe($usuarioAntes);
    expect($item->fresh()->estado)->toBe('VENDIDO');
    expect($item->fresh()->deleted_at)->toBeNull();
});

it('permite el ticket a Admin, Ventas y Auditor, y bloquea a Almacen', function () {
    $venta = ticketVenta([100.0]);

    foreach (['Admin', 'Ventas', 'Auditor'] as $rol) {
        $this->actingAs(User::factory()->create()->assignRole($rol))
            ->get(route('ventas.ticket', $venta))
            ->assertOk();
    }

    $this->actingAs(User::factory()->create()->assignRole('Almacen'))
        ->get(route('ventas.ticket', $venta))
        ->assertForbidden();
});

it('bloquea el ticket a un usuario sin permiso ventas.ver', function () {
    $venta = ticketVenta([100.0]);

    $this->actingAs(User::factory()->create()->assignRole('Operador'))
        ->get(route('ventas.ticket', $venta))
        ->assertForbidden();
});

it('evento sin autenticacion redirige al login', function () {
    $venta = ticketVenta([100.0]);

    $this->get(route('ventas.ticket', $venta))
        ->assertRedirect(route('login'));
});

it('devuelve 404 para una venta inexistente', function () {
    $user = User::factory()->create()->assignRole('Ventas');

    $this->actingAs($user)->get(route('ventas.ticket', 99999999))->assertNotFound();
});

it('sigue imprimiendo una venta cuyo item fue soft-deleted por legacy', function () {
    $venta = ticketVenta([100.0]);
    $item = $venta->detalles->first()->item;

    $item->delete();
    expect(Item::withTrashed()->find($item->id))->not->toBeNull();

    $venta->load('detalles.item');
    expect($venta->detalles->first()->item)->not->toBeNull();

    $user = User::factory()->create()->assignRole('Ventas');
    $this->actingAs($user)->get(route('ventas.ticket', $venta))
        ->assertOk()
        ->assertSee($item->codigo);

    // El item sigue soft-deleted: la impresión no lo restaura ni lo muta.
    expect(Item::find($item->id))->toBeNull();
    expect($item->fresh()->deleted_at)->not->toBeNull();
});
