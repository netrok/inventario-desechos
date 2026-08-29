<?php

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

    foreach (['ventas.ver', 'ventas.crear'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    Movimiento::flushEventListeners();
    app('events')->forget('eloquent.retrieved: '.Item::class);
});

function posSeller(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['ventas.ver', 'ventas.crear']);

    return $user;
}

function posItem(float $precio = 1250.5, string $estado = 'DISPONIBLE'): Item
{
    return Item::create(['estado' => $estado, 'precio' => $precio]);
}

/**
 * =========================
 * Folio VTA-XXXXXX (sequence concurrency-safe)
 * =========================
 */
it('genera folios consecutivos con formato VTA-XXXXXX', function () {
    $a = Venta::create(['user_id' => posSeller()->id, 'total' => 100, 'forma_pago' => 'EFECTIVO']);
    $b = Venta::create(['user_id' => posSeller()->id, 'total' => 200, 'forma_pago' => 'TARJETA']);

    expect($a->folio)->toMatch('/^VTA-\d{6}$/');
    expect($b->folio)->toMatch('/^VTA-\d{6}$/');
    expect((int) substr($b->folio, 4))->toBeGreaterThan((int) substr($a->folio, 4));
});

it('el constraint UNIQUE de folio protege duplicados', function () {
    $venta = Venta::create(['user_id' => posSeller()->id, 'total' => 100, 'forma_pago' => 'EFECTIVO']);

    $threw = false;
    try {
        Venta::query()->forceCreate([
            'folio' => $venta->folio,
            'total' => 50,
            'forma_pago' => 'OTRO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (\Illuminate\Database\QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
});

it('no reutiliza folios consumidos por una transaccion revertida', function () {
    $v1 = Venta::create(['user_id' => posSeller()->id, 'total' => 100, 'forma_pago' => 'EFECTIVO']);
    $f1 = (int) substr($v1->folio, 4);

    try {
        DB::transaction(function (): void {
            Venta::create(['user_id' => posSeller()->id, 'total' => 1, 'forma_pago' => 'OTRO']);
            throw new RuntimeException('fuerza rollback despues de consumir la secuencia');
        });
    } catch (RuntimeException) {
        // esperado: el valor de la secuencia ya consumido no se reutiliza
    }

    $v2 = Venta::create(['user_id' => posSeller()->id, 'total' => 200, 'forma_pago' => 'TARJETA']);

    expect((int) substr($v2->folio, 4))->toBeGreaterThan($f1);
});

/**
 * =========================
 * Venta simple (flujo completo)
 * =========================
 */
it('registra una venta simple de forma atomica', function () {
    $user = posSeller();
    $item = posItem(999.99);

    $this->session(['pos.cart' => [$item->id]]);

    $response = $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'forma_pago' => 'EFECTIVO',
    ]);

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('venta_detalles', 1);
    $this->assertDatabaseCount('movimientos', 1);

    $venta = Venta::first();
    $response->assertRedirect(route('ventas.show', $venta));

    expect($venta->folio)->toMatch('/^VTA-\d{6}$/');
    expect((string) $venta->total)->toBe('999.99');
    expect($venta->forma_pago)->toBe('EFECTIVO');
    expect($venta->user_id)->toBe($user->id);

    $detalle = VentaDetalle::first();
    expect($detalle->venta_id)->toBe($venta->id);
    expect($detalle->item_id)->toBe($item->id);
    expect((string) $detalle->precio)->toBe('999.99');

    $item->refresh();
    expect($item->estado)->toBe('VENDIDO');
    expect($item->deleted_at)->toBeNull();

    $movimiento = Movimiento::first();
    expect($movimiento->tipo)->toBe(Movimiento::TIPO_VENTA);
    expect($movimiento->item_id)->toBe($item->id);
    expect($movimiento->user_id)->toBe($user->id);
    expect($movimiento->de_estado)->toBe('DISPONIBLE');
    expect($movimiento->a_estado)->toBe('VENDIDO');

    expect(session('pos.cart'))->toBe([]);
});

it('registra una venta de varios equipos con el total calculado en el servidor', function () {
    $user = posSeller();

    $a = posItem(100.0);
    $b = posItem(250.5);
    $c = posItem(99.99);

    $this->session(['pos.cart' => [$a->id, $b->id, $c->id]]);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$a->id, $b->id, $c->id],
        'forma_pago' => 'TRANSFERENCIA',
    ]);

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('venta_detalles', 3);
    $this->assertDatabaseCount('movimientos', 3);

    $venta = Venta::first();
    expect((string) $venta->total)->toBe('450.49');
    expect($venta->forma_pago)->toBe('TRANSFERENCIA');

    foreach ([$a, $b, $c] as $item) {
        $item->refresh();
        expect($item->estado)->toBe('VENDIDO');
    }
});

it('ignora un total manipulado y usa siempre el importe real de la BD', function () {
    $user = posSeller();

    $a = posItem(100.0);
    $b = posItem(250.5);

    $this->session(['pos.cart' => [$a->id, $b->id]]);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$a->id, $b->id],
        'forma_pago' => 'EFECTIVO',
        'total' => '1.25',
    ]);

    $this->assertDatabaseCount('ventas', 1);

    expect((string) Venta::first()->total)->toBe('350.50');
});

it('persiste el precio de BD en el detalle aunque se intente manipular', function () {
    $user = posSeller();
    $item = posItem(777.77);

    $this->session(['pos.cart' => [$item->id]]);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'forma_pago' => 'OTRO',
        'precio' => '1.00',
    ]);

    $this->assertDatabaseCount('venta_detalles', 1);
    expect((string) VentaDetalle::first()->precio)->toBe('777.77');
    expect((string) Venta::first()->total)->toBe('777.77');
});

/**
 * =========================
 * Escáner / agregar al carrito
 * =========================
 */
it('el escaner busca por codigo exacto y con normalizacion trim + uppercase', function () {
    $user = posSeller();
    $item = posItem();

    $response = $this->actingAs($user)->post(route('pos.add'), [
        'codigo' => '  '.mb_strtolower($item->codigo).'  ',
    ]);

    $response->assertRedirect(route('pos.index'));
    $response->assertSessionHas('success');
    expect(session('pos.cart'))->toBe([$item->id]);
});

it('el escaner informa cuando el codigo no existe', function () {
    $user = posSeller();

    $this->actingAs($user)
        ->post(route('pos.add'), ['codigo' => 'ITM-999999'])
        ->assertSessionHasErrors('codigo');
});

it('no permite agregar un equipo que ya esta en el carrito', function () {
    $user = posSeller();
    $item = posItem();

    $this->session(['pos.cart' => [$item->id]]);

    $this->actingAs($user)
        ->post(route('pos.add'), ['codigo' => $item->codigo])
        ->assertSessionHasErrors('codigo');

    expect(session('pos.cart'))->toBe([$item->id]);
});

it('no permite agregar equipos RESERVADO, REPARACION, VENDIDO ni BAJA', function ($estado, $fragmento) {
    $user = posSeller();
    $item = posItem(500.0, $estado);

    $this->actingAs($user)
        ->post(route('pos.add'), ['codigo' => $item->codigo])
        ->assertSessionHasErrors('codigo');

    expect(session('errors')->first('codigo'))->toContain($fragmento);
    expect(session('pos.cart'))->toBeNull();
})->with([
    'RESERVADO' => ['RESERVADO', 'RESERVADO'],
    'REPARACION' => ['REPARACION', 'REPARACIÓN'],
    'VENDIDO' => ['VENDIDO', 'ya fue vendido'],
    'BAJA' => ['BAJA', 'dado de baja'],
]);

it('no permite agregar un equipo sin precio asignado', function () {
    $user = posSeller();
    $item = Item::create(['estado' => 'DISPONIBLE']); // sin precio

    $this->actingAs($user)
        ->post(route('pos.add'), ['codigo' => $item->codigo])
        ->assertSessionHasErrors('codigo');
});

it('un estado desconocido/corrupto en BD no es vendible ni por agregar ni por checkout', function () {
    $user = posSeller();
    $item = posItem(500.0);

    // Estado corrupto inyectado directamente (no pasa por la app).
    \Illuminate\Support\Facades\DB::table('items')
        ->where('id', $item->id)
        ->update(['estado' => 'EXTRAVIADO']);

    $this->actingAs($user)
        ->post(route('pos.add'), ['codigo' => $item->codigo])
        ->assertSessionHasErrors('codigo');

    expect(session('errors')->first('codigo'))
        ->toContain('no se encuentra en un estado vendible');
    expect(session('pos.cart'))->toBeNull();

    // Checkout directo sobre el mismo equipo tampoco debe producir venta.
    $this->session(['pos.cart' => [$item->id]]);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'forma_pago' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('items');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('venta_detalles', 0);
    $this->assertDatabaseCount('movimientos', 0);
});

/**
 * =========================
 * Estados no vendibles en checkout (abortan TODA la venta)
 * =========================
 */
it('el checkout aborta toda la venta cuando un equipo ya fue vendido', function () {
    $user = posSeller();
    $vendido = posItem(300.0, 'VENDIDO');

    $this->session(['pos.cart' => [$vendido->id]]);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$vendido->id],
            'forma_pago' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('items');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('venta_detalles', 0);
    $this->assertDatabaseCount('movimientos', 0);
    expect($vendido->refresh()->estado)->toBe('VENDIDO');
});

it('el checkout aborta con BAJA y conserva el carrito', function () {
    $user = posSeller();
    $baja = posItem(300.0, 'BAJA');

    $this->session(['pos.cart' => [$baja->id]]);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$baja->id],
            'forma_pago' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('items');

    $this->assertDatabaseCount('ventas', 0);
    expect(session('pos.cart'))->toBe([$baja->id]);
});

it('hace rollback total si un equipo del carrito deja de ser vendible', function () {
    $user = posSeller();

    $a = posItem(100.0);          // válido
    $b = posItem(200.0, 'VENDIDO'); // inválido (vendido por otra vía)

    $this->session(['pos.cart' => [$a->id, $b->id]]);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$a->id, $b->id],
            'forma_pago' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('items');

    // Nada se escribió: ni venta, ni detalles, ni movimientos.
    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('venta_detalles', 0);
    $this->assertDatabaseCount('movimientos', 0);

    // El primer equipo (que ya tenía lock) volvió a su estado original.
    expect($a->refresh()->estado)->toBe('DISPONIBLE');
    expect($b->refresh()->estado)->toBe('VENDIDO');
});

/**
 * =========================
 * Concurrencia: doble venta del mismo equipo
 * =========================
 */
it('un mismo equipo solo se puede vender una vez (segunda venta aborta)', function () {
    $user = posSeller();
    $item = posItem(150.0);

    $this->session(['pos.cart' => [$item->id]]);
    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'forma_pago' => 'EFECTIVO',
    ]);

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('movimientos', 1);

    // Segundo usuario intenta vender el mismo equipo.
    $other = posSeller();
    $this->session(['pos.cart' => [$item->id]]);
    $this->actingAs($other)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'forma_pago' => 'TARJETA',
    ])->assertSessionHasErrors('items');

    // El segundo no ganó: sin venta nueva, sin segundo Movimiento VENTA.
    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('venta_detalles', 1);
    $this->assertDatabaseCount('movimientos', 1);
    expect(Movimiento::first()->user_id)->toBe($user->id);

    $item->refresh();
    expect($item->estado)->toBe('VENDIDO');
    expect($item->deleted_at)->toBeNull();
});

it('detecta cambios de estado ocurridos entre la lectura y el lock (lectura stale) y aborta', function () {
    $user = posSeller();
    $item = posItem(150.0);

    $flipped = false;

    Item::retrieved(function (Item $item) use (&$flipped) {
        if ($flipped) {
            return;
        }
        $flipped = true;
        // El equipo cambió entre la lectura original y el uso bajo lock.
        $item->estado = 'VENDIDO';
        DB::table('items')->where('id', $item->id)->update(['estado' => 'VENDIDO']);
    });

    $this->session(['pos.cart' => [$item->id]]);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'forma_pago' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('items');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('venta_detalles', 0);
    $this->assertDatabaseCount('movimientos', 0);
});

/**
 * =========================
 * Pedido de venta: validaciones
 * =========================
 */
it('valida que el carrito este presente y no vacio', function () {
    $user = posSeller();

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'forma_pago' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('items');
});

it('valida la forma de pago', function () {
    $user = posSeller();
    $item = posItem();

    $this->session(['pos.cart' => [$item->id]]);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'forma_pago' => 'CREDITO',
        ])
        ->assertSessionHasErrors('forma_pago');
});

it('rechaza un carrito enviado que no coincide con la sesion', function () {
    $user = posSeller();
    $item = posItem();

    $this->session(['pos.cart' => [$item->id]]);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [999999],
            'forma_pago' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('items');

    $this->assertDatabaseCount('ventas', 0);
});

it('hace rollback completo cuando falla la creacion del Movimiento VENTA', function () {
    $user = posSeller();
    $item = posItem(123.45);

    $this->session(['pos.cart' => [$item->id]]);

    // Inyección de fallo: la creación de Movimiento falla en medio del checkout.
    Movimiento::creating(function () {
        throw new RuntimeException('fallo simulado en Movimiento VENTA');
    });

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'forma_pago' => 'EFECTIVO',
        ])
        ->assertStatus(500);

    // Rollback completo: nada quedó escrito, ni siquiera el estado del Item.
    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('venta_detalles', 0);
    $this->assertDatabaseCount('movimientos', 0);

    $item->refresh();
    expect($item->estado)->toBe('DISPONIBLE');
    expect($item->deleted_at)->toBeNull();

    // El carrito se conserva para reintentar/ajustar.
    expect(session('pos.cart'))->toBe([$item->id]);

    // El folio consumido por la secuencia genera gap; NO se intenta reutilizar.
    $siguiente = Venta::create(['user_id' => posSeller()->id, 'total' => 10, 'forma_pago' => 'OTRO']);
    expect((int) substr($siguiente->folio, 4))->toBeGreaterThan(0);
});

/**
 * =========================
 * Unicidad de Item en venta_detalles (defensa a nivel BD)
 * =========================
 */
it('la BD rechaza por UNIQUE que un item pertenezca a dos ventas', function () {
    $ventaA = Venta::create(['user_id' => posSeller()->id, 'total' => 100, 'forma_pago' => 'EFECTIVO']);
    $ventaB = Venta::create(['user_id' => posSeller()->id, 'total' => 200, 'forma_pago' => 'TARJETA']);
    $item = posItem(50.0);

    $ventaA->detalles()->create(['item_id' => $item->id, 'precio' => $item->precio]);

    $threw = false;
    try {
        DB::transaction(function () use ($ventaB, $item) {
            $ventaB->detalles()->create(['item_id' => $item->id, 'precio' => 200]);
        });
    } catch (\Illuminate\Database\QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    $this->assertDatabaseCount('venta_detalles', 1);
});

it('existe la constraint UNIQUE y la FK RESTRICT de venta_detalles.item_id', function () {
    $unique = DB::selectOne("
        SELECT conname FROM pg_constraint
        WHERE conrelid = 'venta_detalles'::regclass
          AND contype = 'u'
          AND conkey = ARRAY[
              (SELECT attnum FROM pg_attribute
               WHERE attrelid = 'venta_detalles'::regclass AND attname = 'item_id')
          ]
    ");
    expect($unique?->conname)->toBe('venta_detalles_item_id_unique');

    $fk = DB::selectOne("
        SELECT confdeltype FROM pg_constraint
        WHERE conrelid = 'venta_detalles'::regclass
          AND contype = 'f'
          AND confrelid = 'items'::regclass
    ");
    expect($fk?->confdeltype)->toBe('r');
});

/**
 * =========================
 * Precisión monetaria (sin float; centavos enteros)
 * =========================
 */
it('suma 0.10 + 0.20 = 0.30 exacto y persiste el precio decimal de cada detalle', function () {
    $user = posSeller();
    $a = posItem(0.1);
    $b = posItem(0.2);

    $this->session(['pos.cart' => [$a->id, $b->id]]);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$a->id, $b->id],
        'forma_pago' => 'EFECTIVO',
    ]);

    $venta = Venta::first();
    expect((string) $venta->total)->toBe('0.30');

    $detalles = VentaDetalle::orderBy('item_id')->get()->pluck('precio')->map(fn ($p) => (string) $p)->all();
    expect($detalles)->toBe(['0.10', '0.20']);
});

it('suma 19.99 + 29.99 + 49.99 = 99.97 exacto sin aproximacion', function () {
    $user = posSeller();
    $a = posItem(19.99);
    $b = posItem(29.99);
    $c = posItem(49.99);

    $this->session(['pos.cart' => [$a->id, $b->id, $c->id]]);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$a->id, $b->id, $c->id],
        'forma_pago' => 'EFECTIVO',
    ]);

    $venta = Venta::first();
    expect((string) $venta->total)->toBe('99.97');

    $detalles = VentaDetalle::orderBy('item_id')->get()->pluck('precio')->map(fn ($p) => (string) $p)->all();
    expect($detalles)->toBe(['19.99', '29.99', '49.99']);
});

/**
 * =========================
 * Actor de venta (ventas.user_id NOT NULL + FK RESTRICT)
 * =========================
 */
it('ventas.user_id es NOT NULL y su FK es RESTRICT en PostgreSQL', function () {
    $nullable = DB::selectOne("
        SELECT is_nullable FROM information_schema.columns
        WHERE table_name = 'ventas' AND column_name = 'user_id'
    ");
    expect($nullable?->is_nullable)->toBe('NO');

    $fk = DB::selectOne("
        SELECT confdeltype FROM pg_constraint
        WHERE conrelid = 'ventas'::regclass
          AND contype = 'f'
          AND confrelid = 'users'::regclass
    ");
    expect($fk?->confdeltype)->toBe('r'); // r = RESTRICT
});

it('la BD impide borrar el usuario de una venta y conserva el actor historico', function () {
    $vendedor = User::factory()->create();
    $venta = Venta::create(['user_id' => $vendedor->id, 'total' => 500, 'forma_pago' => 'EFECTIVO']);

    $threw = false;
    try {
        DB::transaction(function () use ($vendedor) {
            $vendedor->delete();
        });
    } catch (\Illuminate\Database\QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    $this->assertDatabaseHas('users', ['id' => $vendedor->id]);
    $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'user_id' => $vendedor->id]);
});

/**
 * =========================
 * Historial / detalle
 * =========================
 */
it('el listado de ventas muestra folio y total y el detalle enlaza los equipos', function () {
    $user = posSeller();
    $item = posItem(555.0);

    $this->session(['pos.cart' => [$item->id]]);
    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'forma_pago' => 'EFECTIVO',
    ]);

    $venta = Venta::first();

    $this->actingAs($user)
        ->get(route('ventas.index'))
        ->assertOk()
        ->assertSee($venta->folio)
        ->assertSee('555.00');

    $this->actingAs($user)
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee($venta->folio)
        ->assertSee($item->codigo);
});
