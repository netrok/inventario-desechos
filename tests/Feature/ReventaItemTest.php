<?php

use App\Models\Cliente;
use App\Models\DocumentoPostventa;
use App\Models\DocumentoPostventaDetalle;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'ventas.ver',
        'ventas.crear',
        'ventas.cancelar',
        'ventas.devolver',
        'items.ver',
        'items.cambiar_estado',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    Movimiento::flushEventListeners();
    app('events')->forget('eloquent.retrieved: '.Item::class);
});

function reventaSeller(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['ventas.ver', 'ventas.crear', 'ventas.cancelar']);

    return $user;
}

function reventaAdmin(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        'ventas.ver',
        'ventas.crear',
        'ventas.cancelar',
        'ventas.devolver',
        'items.ver',
        'items.cambiar_estado',
    ]);

    return $user;
}

function reventaItem(float $precio = 1000.0): Item
{
    return Item::create(['estado' => 'DISPONIBLE', 'precio' => $precio]);
}

function reventaCliente(string $nombre = 'Cliente Reventa'): Cliente
{
    return Cliente::create(['nombre' => $nombre]);
}

function reventaSession(Item $item, ?Cliente $cliente = null): void
{
    test()->session([
        'pos.cart' => [$item->id],
        'pos.cliente_id' => $cliente?->id ?? reventaCliente()->id,
    ]);
}

function venderDesdePos(User $user, Item $item, string $formaPago = 'EFECTIVO'): Venta
{
    reventaSession($item);

    test()->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'forma_pago' => $formaPago,
        ])
        ->assertSessionHasNoErrors();

    return Venta::query()->orderByDesc('id')->first();
}

/**
 * =========================
 * Reventa tras cancelación (Caso A del piloto)
 * =========================
 */
it('permite revender el mismo item tras cancelar la primera venta', function () {
    $user = reventaSeller();
    $item = reventaItem(1500.0);

    // Venta 1
    $venta1 = venderDesdePos($user, $item);
    expect($venta1->estado)->toBe(Venta::ESTADO_ACTIVA);
    expect($item->fresh()->estado)->toBe('VENDIDO');

    // Cancelación Venta 1
    $this->actingAs($user)
        ->post(route('ventas.cancelar.store', $venta1), [
            'motivo' => 'Cancelación de prueba para reventa.',
        ])
        ->assertSessionHasNoErrors();

    expect($venta1->fresh()->estado)->toBe(Venta::ESTADO_CANCELADA);
    expect($item->fresh()->estado)->toBe('DISPONIBLE');

    // Reventa: Venta 2
    $venta2 = venderDesdePos($user, $item, 'TARJETA');

    $ventas = Venta::query()->orderBy('id')->get();
    expect($ventas)->toHaveCount(2);
    expect($venta2->id)->toBeGreaterThan($venta1->id);
    expect($venta1->fresh()->estado)->toBe(Venta::ESTADO_CANCELADA);
    expect($venta2->fresh()->estado)->toBe(Venta::ESTADO_ACTIVA);
    expect($item->fresh()->estado)->toBe('VENDIDO');

    // Existen DOS VentaDetalle históricos para el MISMO item en dos ventas.
    $detalles = VentaDetalle::query()->where('item_id', $item->id)->orderBy('id')->get();
    expect($detalles)->toHaveCount(2);
    expect($detalles->pluck('venta_id')->all())->toBe([$venta1->id, $venta2->id]);
});

it('la BD impide el mismo item dos veces en la misma venta (UNIQUE venta_id,item_id)', function () {
    $venta = Venta::create(['user_id' => reventaSeller()->id, 'total' => 200, 'forma_pago' => 'EFECTIVO']);
    $item = reventaItem();

    $venta->detalles()->create(['item_id' => $item->id, 'precio' => $item->precio]);

    $threw = false;
    try {
        DB::transaction(function () use ($venta, $item) {
            $venta->detalles()->create(['item_id' => $item->id, 'precio' => 999]);
        });
    } catch (\Illuminate\Database\QueryException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    $this->assertDatabaseCount('venta_detalles', 1);
});

it('el esquema ya no tiene UNIQUE global de item_id: usa compuesto + índice simple + FK RESTRICT', function () {
    $idItem = DB::selectOne("
        SELECT attnum FROM pg_attribute
        WHERE attrelid = 'venta_detalles'::regclass AND attname = 'item_id'
    ")->attnum;
    $idVenta = DB::selectOne("
        SELECT attnum FROM pg_attribute
        WHERE attrelid = 'venta_detalles'::regclass AND attname = 'venta_id'
    ")->attnum;

    $compuesto = DB::selectOne("
        SELECT conname FROM pg_constraint
        WHERE conrelid = 'venta_detalles'::regclass
          AND contype = 'u'
          AND conkey = ARRAY[$idVenta::smallint, $idItem::smallint]
    ");
    expect($compuesto?->conname)->toBe('venta_detalles_venta_id_item_id_unique');

    $global = DB::selectOne("
        SELECT conname FROM pg_constraint
        WHERE conrelid = 'venta_detalles'::regclass
          AND contype = 'u'
          AND conkey = ARRAY[$idItem::smallint]
    ");
    expect($global)->toBeNull();

    $indice = DB::selectOne("
        SELECT indexname FROM pg_indexes
        WHERE tablename = 'venta_detalles'
          AND indexname = 'venta_detalles_item_id_index'
    ");
    expect($indice?->indexname)->toBe('venta_detalles_item_id_index');

    $fk = DB::selectOne("
        SELECT confdeltype FROM pg_constraint
        WHERE conrelid = 'venta_detalles'::regclass
          AND contype = 'f'
          AND confrelid = 'items'::regclass
    ");
    expect($fk?->confdeltype)->toBe('r');
});

it('un item en una venta activa (VENDIDO) no puede revenderse', function () {
    $user = reventaSeller();
    $item = reventaItem(1200.0);

    venderDesdePos($user, $item);
    expect(Venta::count())->toBe(1);

    // Segundo intento sobre el MISMO item todavía VENDIDO.
    reventaSession($item);
    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'forma_pago' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('items');

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('venta_detalles', 1);
    expect($item->fresh()->estado)->toBe('VENDIDO');
});

it('serializa la reventa por estado+lock (emulación secuencial; sin concurrencia real)', function () {
    // NOTA: no hay test a nivel HTTP paralelo real (un solo proceso de test).
    // La garantía de concurrencia se apoya en lockForUpdate + revalidación de
    // estado bajo lock dentro de DB::transaction: dos peticiones "simultáneas"
    // se serializan y la segunda revalida y aborta controladamente.
    $user = reventaSeller();
    $item = reventaItem(800.0);

    // Primera petición concreta: vende.
    venderDesdePos($user, $item);
    expect($item->fresh()->estado)->toBe('VENDIDO');

    // Segunda petición concreta (lo que vería la petición B tras obtener el lock):
    // el estado ya NO es DISPONIBLE -> error controlado, sin filas nuevas.
    reventaSession($item);
    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'forma_pago' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('items');

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('venta_detalles', 1);
    expect(VentaDetalle::query()->where('item_id', $item->id)->count())->toBe(1);
});

it('permite revender tras devolución y reingreso autorizado DEVUELTO->DISPONIBLE', function () {
    $admin = reventaAdmin();
    $item = reventaItem(900.0);

    // Venta 1
    $venta1 = venderDesdePos($admin, $item);
    expect($item->fresh()->estado)->toBe('VENDIDO');

    // Devolución total de la Venta 1
    $detalleId = $venta1->detalles()->first()->id;

    $this->actingAs($admin)
        ->post(route('ventas.devolver.store', $venta1), [
            'motivo' => 'El cliente devuelve el equipo.',
            'forma_reembolso' => 'EFECTIVO',
            'detalles' => [$detalleId],
        ])
        ->assertSessionHasNoErrors();

    expect($item->fresh()->estado)->toBe('DEVUELTO');
    expect($venta1->fresh()->estado)->toBe(Venta::ESTADO_DEVUELTA);

    // Reingreso autorizado (cambio de estado): DEVUELTO -> DISPONIBLE.
    $this->actingAs($admin)
        ->post(route('items.changeEstado', $item->id), [
            'estado' => 'DISPONIBLE',
            'notas' => 'Reingreso tras devolución.',
        ])
        ->assertSessionHasNoErrors();

    expect($item->fresh()->estado)->toBe('DISPONIBLE');

    // Reventa: Venta 2
    reventaSession($item);
    $this->actingAs($admin)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'forma_pago' => 'TRANSFERENCIA',
        ])
        ->assertSessionHasNoErrors();

    expect(Venta::count())->toBe(2);
    expect($item->fresh()->estado)->toBe('VENDIDO');
    expect(VentaDetalle::query()->where('item_id', $item->id)->count())->toBe(2);
    expect(DocumentoPostventaDetalle::query()->where('item_id', $item->id)->count())->toBe(1);
});

it('cancelar la Venta 2 afecta solo su VentaDetalle y deja separadas las postventas', function () {
    $user = reventaSeller();
    $item = reventaItem(1000.0);

    $venta1 = venderDesdePos($user, $item);

    $this->actingAs($user)
        ->post(route('ventas.cancelar.store', $venta1), [
            'motivo' => 'Primera cancelación (histórica).',
        ])
        ->assertSessionHasNoErrors();

    $venta2 = venderDesdePos($user, $item, 'TARJETA');

    // Cancelar de nuevo la VENTA 1 (ya CANCELADA) DEBE fallar controladamente.
    $this->actingAs($user)
        ->post(route('ventas.cancelar.store', $venta1->fresh()), [
            'motivo' => 'Intento inválido sobre la venta cancelada.',
        ])
        ->assertSessionHasErrors('motivo');

    // Cancelar la VENTA 2: actúa solo sobre su propio detalle.
    $this->actingAs($user)
        ->post(route('ventas.cancelar.store', $venta2), [
            'motivo' => 'Cancelación de la venta nueva.',
        ])
        ->assertSessionHasNoErrors();

    expect($venta1->fresh()->estado)->toBe(Venta::ESTADO_CANCELADA);
    expect($venta2->fresh()->estado)->toBe(Venta::ESTADO_CANCELADA);
    expect($item->fresh()->estado)->toBe('DISPONIBLE');

    // Cada venta conserva EXACTAMENTE su propio VentaDetalle histórico.
    expect($venta1->detalles()->count())->toBe(1);
    expect($venta2->detalles()->count())->toBe(1);
    expect(VentaDetalle::query()->where('item_id', $item->id)->count())->toBe(2);

    // Dos documentos postventa, cada uno ligado a su detalle específico.
    $docs = DocumentoPostventa::query()->orderBy('id')->get();
    expect($docs)->toHaveCount(2);
    expect($docs[0]->venta_id)->toBe($venta1->id);
    expect($docs[1]->venta_id)->toBe($venta2->id);
    expect($docs[0]->detalles()->first()->venta_detalle_id)
        ->not->toBe($docs[1]->detalles()->first()->venta_detalle_id);
});

it('el ticket de la Venta 1 sigue siendo histórico tras la Venta 2 con otro precio', function () {
    $user = reventaSeller();
    $item = reventaItem(1000.0);

    // Venta 1 a 1,000.00
    $venta1 = venderDesdePos($user, $item);
    $this->actingAs($user)
        ->post(route('ventas.cancelar.store', $venta1), [
            'motivo' => 'Cancelación para reventa.',
        ])
        ->assertSessionHasNoErrors();

    // El Item sube de precio para la reventa.
    $item->update(['precio' => 1500.0]);

    // Venta 2 a 1,500.00
    $venta2 = venderDesdePos($user, $item, 'TRANSFERENCIA');

    expect((string) $venta1->fresh()->total)->toBe('1000.00');
    expect((string) $venta2->fresh()->total)->toBe('1500.00');

    // Los tickets no se contaminan entre ventas.
    $this->actingAs($user)->get(route('ventas.ticket', $venta1))
        ->assertOk()
        ->assertSee('1,000.00')
        ->assertDontSee('1,500.00');

    $this->actingAs($user)->get(route('ventas.ticket', $venta2))
        ->assertOk()
        ->assertSee('1,500.00');
});

it('un intento fallido de reventa hace rollback completo sin dejar nada parcial', function () {
    $user = reventaSeller();
    $item = reventaItem(1100.0);

    $venta1 = venderDesdePos($user, $item);

    $this->actingAs($user)
        ->post(route('ventas.cancelar.store', $venta1), [
            'motivo' => 'Cancelación para preparar reventa.',
        ])
        ->assertSessionHasNoErrors();

    expect($item->fresh()->estado)->toBe('DISPONIBLE');

    // Fallo simulado en el checkout de la reventa (creación de Movimiento VENTA).
    Movimiento::creating(function (Movimiento $m) {
        if ($m->tipo === Movimiento::TIPO_VENTA) {
            throw new RuntimeException('fallo simulado en la reventa');
        }
    });

    reventaSession($item);
    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'forma_pago' => 'EFECTIVO',
        ])
        ->assertStatus(500);

    // Rollback completo: nada de la Venta 2 quedó escrito.
    expect(Venta::count())->toBe(1);
    expect(VentaDetalle::query()->where('venta_id', $venta1->id)->count())->toBe(1);
    expect(VentaDetalle::query()->where('venta_id', '!=', $venta1->id)->count())->toBe(0);
    expect(Movimiento::query()->where('tipo', Movimiento::TIPO_VENTA)->count())->toBe(1);
    expect($item->fresh()->estado)->toBe('DISPONIBLE');

    // El carrito se conserva para reintentar.
    expect(session('pos.cart'))->toBe([$item->id]);
});

it('la FK RESTRICT de items sigue impidiendo borrar un item con historial de ventas', function () {
    $user = reventaSeller();
    $item = reventaItem(100.0);

    venderDesdePos($user, $item);

    $threw = false;
    try {
        DB::transaction(function () use ($item) {
            DB::table('items')->where('id', $item->id)->delete();
        });
    } catch (\Illuminate\Database\QueryException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    expect(DB::table('items')->where('id', $item->id)->exists())->toBeTrue();
});
