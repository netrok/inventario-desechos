<?php

use App\Models\DocumentoPostventa;
use App\Models\DocumentoPostventaDetalle;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\User;
use App\Models\Venta;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'items.ver',
        'ventas.ver',
        'ventas.crear',
        'ventas.cancelar',
        'ventas.devolver',
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

/**
 * Usuario con permisos de postventa (admin equivalente).
 */
function postventaAdmin(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        'ventas.ver',
        'ventas.crear',
        'ventas.cancelar',
        'ventas.devolver',
        'items.ver',
    ]);

    return $user;
}

/**
 * Crea una venta YA vendida (items en VENDIDO + detalles + total server-side).
 *
 * @param  array<float>  $precios
 */
function ventaVendida(array $precios = [100.0, 250.5], string $estadoVenta = 'ACTIVA'): Venta
{
    $vendedor = User::factory()->create();

    $items = collect($precios)->map(
        fn (float $p) => Item::create(['estado' => 'VENDIDO', 'precio' => $p])
    );

    $total = array_sum(array_map(fn (float $p) => Money::aCentavos($p), $precios));

    $venta = Venta::create([
        'user_id' => $vendedor->id,
        'total' => Money::aPrecio($total),
        'forma_pago' => 'EFECTIVO',
    ]);

    foreach ($items as $item) {
        $venta->detalles()->create(['item_id' => $item->id, 'precio' => $item->precio]);
    }

    if ($estadoVenta !== 'ACTIVA') {
        DB::table('ventas')->where('id', $venta->id)->update(['estado' => $estadoVenta]);
    }

    return $venta->refresh();
}

/**
 * =========================
 * Folio DEV-XXXXXX (sequence concurrency-safe)
 * =========================
 */
it('asigna folios DEV-XXXXXX consecutivos via sequence', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0]);
    openCajaFor($admin);

    $seq = DB::selectOne('SELECT last_value, is_called FROM documentos_postventa_folio_seq_generator');
    $siguiente = $seq->is_called ? (int) $seq->last_value + 1 : (int) $seq->last_value;
    $folioEsperado = 'DEV-'.str_pad((string) $siguiente, 6, '0', STR_PAD_LEFT);

    $this->actingAs($admin)->post(route('ventas.cancelar.store', $venta), [
        'motivo' => 'Reversa total por error del cliente.',
        'forma_reembolso' => 'EFECTIVO',
    ]);

    $doc = DocumentoPostventa::where('folio', $folioEsperado)->first();

    expect($doc)->not->toBeNull();
    expect($doc->folio)->toMatch('/^DEV-\d{6}$/');
    expect($doc->folio)->toBe($folioEsperado);
    expect($doc->tipo)->toBe(DocumentoPostventa::TIPO_CANCELACION);
});

it('no permite duplicar un folio postventa (UNIQUE BD)', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0]);
    openCajaFor($admin);

    $this->actingAs($admin)->post(route('ventas.cancelar.store', $venta), [
        'motivo' => 'Reversa total por error del cliente.',
        'forma_reembolso' => 'EFECTIVO',
    ]);

    $folio = DocumentoPostventa::first()->folio;

    $threw = false;
    try {
        DocumentoPostventa::query()->forceCreate([
            'folio' => $folio,
            'venta_id' => $venta->id,
            'user_id' => $admin->id,
            'tipo' => 'DEVOLUCION',
            'motivo' => 'Duplicado forzado.',
            'total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (Illuminate\Database\QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
});

/**
 * =========================
 * CANCELACIÓN TOTAL (reversa)
 * =========================
 */
it('cancela una venta ACTIVA y revierte todos los equipos a DISPONIBLE', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0, 250.5]);
    openCajaFor($admin);

    $this->actingAs($admin)->post(route('ventas.cancelar.store', $venta), [
        'motivo' => 'Reversa total por solicitud del cliente.',
        'forma_reembolso' => 'EFECTIVO',
    ])->assertRedirect();

    // Ni venta, ni detalles, ni items se borran: todo queda como historial.
    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('venta_detalles', 2);

    $venta->refresh();
    expect($venta->estado)->toBe(Venta::ESTADO_CANCELADA);

    $doc = DocumentoPostventa::where('venta_id', $venta->id)->first();
    expect($doc)->not->toBeNull();
    expect($doc->tipo)->toBe(DocumentoPostventa::TIPO_CANCELACION);
    expect((string) $doc->total)->toBe('350.50');
    expect($doc->motivo)->toBe('Reversa total por solicitud del cliente.');
    expect($doc->user_id)->toBe($admin->id);

    // Un detalle por cada item de la venta + estado DISPONIBLE + movimiento.
    expect(DocumentoPostventaDetalle::count())->toBe(2);

    foreach ($venta->detalles as $detalle) {
        $item = $detalle->item->refresh();
        expect($item->estado)->toBe('DISPONIBLE');
        expect($item->deleted_at)->toBeNull();
    }

    expect(Movimiento::where('tipo', Movimiento::TIPO_CANCELACION_VENTA)->count())->toBe(2);
    $mov = Movimiento::where('tipo', Movimiento::TIPO_CANCELACION_VENTA)->first();
    expect($mov->de_estado)->toBe('VENDIDO');
    expect($mov->a_estado)->toBe('DISPONIBLE');
    expect($mov->user_id)->toBe($admin->id);
    expect($mov->notas)->toContain($venta->folio);
    expect($mov->notas)->toContain($doc->folio);
});

it('requiere motivo de cancelacion con al menos 5 caracteres', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0]);
    openCajaFor($admin);

    $this->actingAs($admin)
        ->post(route('ventas.cancelar.store', $venta), [
            'motivo' => 'corto', // 5 chars: pasa
            'forma_reembolso' => 'EFECTIVO',
        ])
        ->assertRedirect();

    expect(DocumentoPostventa::count())->toBe(1);

    $otra = ventaVendida([100.0]);
    $this->actingAs($admin)
        ->post(route('ventas.cancelar.store', $otra), ['motivo' => 'cor', 'forma_reembolso' => 'EFECTIVO']) // < 5 chars
        ->assertSessionHasErrors('motivo');

    expect(DocumentoPostventa::count())->toBe(1);
    expect($otra->refresh()->estado)->toBe(Venta::ESTADO_ACTIVA);
});

it('no cancela una venta que ya no esta ACTIVA', function (string $estadoNoActiva) {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0], $estadoNoActiva);
    openCajaFor($admin);

    $this->actingAs($admin)
        ->get(route('ventas.cancelar', $venta))
        ->assertStatus(409);

    $this->actingAs($admin)
        ->post(route('ventas.cancelar.store', $venta), [
            'motivo' => 'Intento de cancelar despues de operaciones previas.',
            'forma_reembolso' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('motivo');

    $this->assertDatabaseCount('documentos_postventa', 0);
})->with([
    'PARCIALMENTE_DEVUELTA' => ['PARCIALMENTE_DEVUELTA'],
    'DEVUELTA' => ['DEVUELTA'],
    'CANCELADA' => ['CANCELADA'],
]);

it('no cancela una venta con una devolucion previa', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0, 250.5]);
    openCajaFor($admin);

    $this->actingAs($admin)->post(route('ventas.devolver.store', $venta), [
        'detalles' => [$venta->detalles()->first()->id],
        'motivo' => 'Primera devolucion parcial.',
        'forma_reembolso' => 'EFECTIVO',
    ])->assertRedirect();

    $this->assertDatabaseCount('documentos_postventa', 1);

    $this->actingAs($admin)
        ->post(route('ventas.cancelar.store', $venta), [
            'motivo' => 'Intentar cancelar tras una devolucion previa.',
            'forma_reembolso' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('motivo');

    $this->assertDatabaseCount('documentos_postventa', 1);
    expect($venta->refresh()->estado)->toBe(Venta::ESTADO_PARCIALMENTE_DEVUELTA);
});

it('aborta la cancelacion si un item ya no esta VENDIDO', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0, 250.5]);
    openCajaFor($admin);

    // Uno de los items cambió entre la lectura y el proceso (se da de baja).
    $venta->detalles()->first()->item->update(['estado' => 'BAJA']);

    $this->actingAs($admin)
        ->post(route('ventas.cancelar.store', $venta), [
            'motivo' => 'La reversa total no debe continuar.',
            'forma_reembolso' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('motivo');

    $this->assertDatabaseCount('documentos_postventa', 0);
    $this->assertDatabaseCount('documento_postventa_detalles', 0);
    $this->assertDatabaseCount('movimientos', 0);
});

it('aborta la cancelacion si el total no coincide con la suma de los detalles', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0, 250.5]);
    openCajaFor($admin);

    // Total manipulado a nivel BD: la reversa derivada server-side ya no cuadra.
    DB::table('ventas')->where('id', $venta->id)->update(['total' => 1.00]);

    $this->actingAs($admin)
        ->post(route('ventas.cancelar.store', $venta), [
            'motivo' => 'El importe revertido no coincide con los detalles.',
            'forma_reembolso' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('motivo');

    $this->assertDatabaseCount('documentos_postventa', 0);
    foreach ($venta->detalles as $detalle) {
        expect($detalle->item->refresh()->estado)->toBe('VENDIDO');
    }
    expect($venta->refresh()->estado)->toBe(Venta::ESTADO_ACTIVA);
});

it('hace rollback completo cuando falla la creacion del Movimiento en la cancelacion', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([123.45]);
    openCajaFor($admin);

    Movimiento::creating(function () {
        throw new RuntimeException('fallo simulado en el movimiento de cancelacion');
    });

    $this->actingAs($admin)
        ->post(route('ventas.cancelar.store', $venta), [
            'motivo' => 'Reversa total que debe revertirse en su totalidad.',
            'forma_reembolso' => 'EFECTIVO',
        ])
        ->assertStatus(500);

    $this->assertDatabaseCount('documentos_postventa', 0);
    $this->assertDatabaseCount('documento_postventa_detalles', 0);
    $this->assertDatabaseCount('movimientos', 0);

    expect($venta->refresh()->estado)->toBe(Venta::ESTADO_ACTIVA);
    expect($venta->detalles()->first()->item->refresh()->estado)->toBe('VENDIDO');
});

/**
 * =========================
 * DEVOLUCIÓN (parcial y total)
 * =========================
 */
it('registra una devolucion parcial e ingresa el item a DEVUELTO', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0, 250.5]);

    [$detalleA, $detalleB] = $venta->detalles()->orderBy('id')->get();

    $this->actingAs($admin)->post(route('ventas.devolver.store', $venta), [
        'detalles' => [$detalleA->id],
        'motivo' => 'Equipo presenta falla funcional.',
        'forma_reembolso' => 'TARJETA',
        'referencia_reembolso' => 'DEV-TARJETA-TEST',
    ])->assertRedirect();

    $doc = DocumentoPostventa::where('venta_id', $venta->id)->first();
    expect($doc)->not->toBeNull();
    expect($doc->tipo)->toBe(DocumentoPostventa::TIPO_DEVOLUCION);
    expect((string) $doc->total)->toBe('100.00');
    expect($doc->forma_reembolso)->toBe('TARJETA');
    expect($doc->motivo)->toBe('Equipo presenta falla funcional.');

    expect(DocumentoPostventaDetalle::count())->toBe(1);

    $venta->refresh();
    expect($venta->estado)->toBe(Venta::ESTADO_PARCIALMENTE_DEVUELTA);

    expect($detalleA->item->refresh()->estado)->toBe('DEVUELTO');
    expect($detalleB->item->refresh()->estado)->toBe('VENDIDO');

    $mov = Movimiento::where('tipo', Movimiento::TIPO_DEVOLUCION_VENTA)->first();
    expect($mov->item_id)->toBe($detalleA->item_id);
    expect($mov->de_estado)->toBe('VENDIDO');
    expect($mov->a_estado)->toBe('DEVUELTO');
    expect($mov->notas)->toContain($venta->folio);
});

it('registra una devolucion total y deja la venta en DEVUELTA', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0, 250.5]);

    $ids = $venta->detalles()->orderBy('id')->pluck('id')->all();

    $this->actingAs($admin)->post(route('ventas.devolver.store', $venta), [
        'detalles' => $ids,
        'motivo' => 'Cliente devolvio todos los equipos.',
        'forma_reembolso' => 'TRANSFERENCIA',
        'referencia_reembolso' => 'DEV-TRANSFER-TEST',
    ])->assertRedirect();

    $doc = DocumentoPostventa::first();
    expect((string) $doc->total)->toBe('350.50');
    expect($doc->forma_reembolso)->toBe('TRANSFERENCIA');

    expect($venta->refresh()->estado)->toBe(Venta::ESTADO_DEVUELTA);

    foreach ($venta->detalles as $detalle) {
        expect($detalle->item->refresh()->estado)->toBe('DEVUELTO');
    }
});

it('no permite devolver un detalle ya devuelto en otro documento', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0, 250.5]);
    openCajaFor($admin);

    $detalle = $venta->detalles()->first();

    $this->actingAs($admin)->post(route('ventas.devolver.store', $venta), [
        'detalles' => [$detalle->id],
        'motivo' => 'Primera devolucion.',
        'forma_reembolso' => 'EFECTIVO',
    ])->assertRedirect();

    $this->assertDatabaseCount('documentos_postventa', 1);

    $this->actingAs($admin)->post(route('ventas.devolver.store', $venta), [
        'detalles' => [$detalle->id],
        'motivo' => 'Segunda devolucion del mismo equipo.',
        'forma_reembolso' => 'EFECTIVO',
    ])->assertSessionHasErrors('detalles');

    $this->assertDatabaseCount('documentos_postventa', 1);
    expect(DocumentoPostventa::where('venta_id', $venta->id)->count())->toBe(1);
});

it('rechaza devolver detalles que no pertenecen a la venta', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0]);
    $otra = ventaVendida([50.0]);
    $detalleAjeno = $otra->detalles()->first();
    openCajaFor($admin);

    $this->actingAs($admin)
        ->post(route('ventas.devolver.store', $venta), [
            'detalles' => [$detalleAjeno->id],
            'motivo' => 'Detalle de otra venta.',
            'forma_reembolso' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('detalles');

    $this->assertDatabaseCount('documentos_postventa', 0);
    expect($detalleAjeno->item->refresh()->estado)->toBe('VENDIDO');
});

it('calcula el importe server-side e ignora cualquier importe del navegador', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([777.77]);

    $detalle = $venta->detalles()->first();

    $this->actingAs($admin)->post(route('ventas.devolver.store', $venta), [
        'detalles' => [$detalle->id],
        'motivo' => 'Devolucion con importe manipulado.',
        'forma_reembolso' => 'OTRO',
        'importe' => '1.00',
    ])->assertRedirect();

    $doc = DocumentoPostventa::first();
    expect((string) $doc->total)->toBe('777.77');

    $docDetalle = DocumentoPostventaDetalle::first();
    expect((string) $docDetalle->importe)->toBe('777.77');
});

it('valida forma de reembolso y seleccion de equipos', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0]);

    $detalle = $venta->detalles()->first();

    $this->actingAs($admin)
        ->post(route('ventas.devolver.store', $venta), [
            'detalles' => [$detalle->id],
            'motivo' => 'Sin forma de reembolso.',
            'forma_reembolso' => 'PAGO_EN_ESPECIE',
        ])
        ->assertSessionHasErrors('forma_reembolso');

    $this->actingAs($admin)
        ->post(route('ventas.devolver.store', $venta), [
            'detalles' => [],
            'motivo' => 'Sin equipos seleccionados.',
            'forma_reembolso' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('detalles');
});

it('aborta la devolucion si el item ya no esta VENDIDO', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0, 250.5]);
    openCajaFor($admin);

    $detalleA = $venta->detalles()->orderBy('id')->first();
    $detalleA->item->update(['estado' => 'BAJA']);

    $this->actingAs($admin)
        ->post(route('ventas.devolver.store', $venta), [
            'detalles' => [$detalleA->id, $venta->detalles()->orderBy('id')->skip(1)->first()->id],
            'motivo' => 'Uno de los equipos ya fue dado de baja.',
            'forma_reembolso' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('detalles');

    $this->assertDatabaseCount('documentos_postventa', 0);
});

it('la BD impide por UNIQUE devolver dos veces el mismo detalle', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0]);
    $detalle = $venta->detalles()->first();

    $doc = DocumentoPostventa::create([
        'venta_id' => $venta->id,
        'user_id' => $admin->id,
        'tipo' => DocumentoPostventa::TIPO_DEVOLUCION,
        'total' => 100,
        'motivo' => 'Primer documento.',
        'forma_reembolso' => 'EFECTIVO',
    ]);

    DocumentoPostventaDetalle::create([
        'documento_postventa_id' => $doc->id,
        'venta_detalle_id' => $detalle->id,
        'item_id' => $detalle->item_id,
        'importe' => 100,
    ]);

    $threw = false;
    try {
        DB::transaction(function () use ($doc, $detalle) {
            DocumentoPostventaDetalle::create([
                'documento_postventa_id' => $doc->id,
                'venta_detalle_id' => $detalle->id,
                'item_id' => $detalle->item_id,
                'importe' => 100,
            ]);
        });
    } catch (Illuminate\Database\QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    $this->assertDatabaseCount('documento_postventa_detalles', 1);
});

/**
 * =========================
 * Elegibilidad / exclusión mutua
 * =========================
 */
it('una vez cancelada la venta no admite devoluciones', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0, 250.5]);
    openCajaFor($admin);

    $this->actingAs($admin)->post(route('ventas.cancelar.store', $venta), [
        'motivo' => 'Reversa total por error del cliente.',
        'forma_reembolso' => 'EFECTIVO',
    ])->assertRedirect();

    $ids = $venta->detalles()->orderBy('id')->pluck('id')->all();

    $this->actingAs($admin)
        ->get(route('ventas.devolver', $venta))
        ->assertStatus(409);

    $this->actingAs($admin)
        ->post(route('ventas.devolver.store', $venta), [
            'detalles' => $ids,
            'motivo' => 'Devolucion imposible sobre venta cancelada.',
            'forma_reembolso' => 'EFECTIVO',
        ])
        ->assertSessionHasErrors('detalles');

    $this->assertDatabaseCount('documentos_postventa', 1);
});

it('los GET de formulario son de solo lectura (no mutan datos)', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0, 250.5]);

    $this->actingAs($admin)->get(route('ventas.cancelar', $venta))->assertOk();
    $this->actingAs($admin)->get(route('ventas.devolver', $venta))->assertOk();

    $this->assertDatabaseCount('documentos_postventa', 0);
    $this->assertDatabaseCount('movimientos', 0);
    expect($venta->refresh()->estado)->toBe(Venta::ESTADO_ACTIVA);
});

/**
 * =========================
 * Constraints / integridad BD
 * =========================
 */
it('existen los constraints CHECK y FK RESTRICT de postventa', function () {
    $tipoCheck = DB::selectOne("
        SELECT conname FROM pg_constraint
        WHERE conrelid = 'documentos_postventa'::regclass AND contype = 'c'
          AND conname = 'documentos_postventa_tipo_check'
    ");
    expect($tipoCheck)->not->toBeNull();

    $formaCheck = DB::selectOne("
        SELECT conname FROM pg_constraint
        WHERE conrelid = 'documentos_postventa'::regclass AND contype = 'c'
          AND conname = 'documentos_postventa_forma_reembolso_check'
    ");
    expect($formaCheck)->not->toBeNull();

    $fkVenta = DB::selectOne("
        SELECT confdeltype FROM pg_constraint
        WHERE conrelid = 'documentos_postventa'::regclass
          AND contype = 'f' AND confrelid = 'ventas'::regclass
    ");
    expect($fkVenta?->confdeltype)->toBe('r');

    $fkUser = DB::selectOne("
        SELECT confdeltype FROM pg_constraint
        WHERE conrelid = 'documentos_postventa'::regclass
          AND contype = 'f' AND confrelid = 'users'::regclass
    ");
    expect($fkUser?->confdeltype)->toBe('r');

    $uniqueDetalle = DB::selectOne("
        SELECT conname FROM pg_constraint
        WHERE conrelid = 'documento_postventa_detalles'::regclass
          AND contype = 'u' AND conname = 'documento_postventa_detalles_venta_detalle_id_unique'
    ");
    expect($uniqueDetalle)->not->toBeNull();
});

it('la columna estado de ventas tiene CHECK y las ventas existentes quedan ACTIVA', function () {
    $check = DB::selectOne("
        SELECT conname FROM pg_constraint
        WHERE conrelid = 'ventas'::regclass AND contype = 'c'
          AND conname = 'ventas_estado_check'
    ");
    expect($check)->not->toBeNull();

    $default = DB::selectOne("
        SELECT column_default FROM information_schema.columns
        WHERE table_name = 'ventas' AND column_name = 'estado'
    ");
    expect($default?->column_default)->toContain('ACTIVA');
});

it('postgres rechaza un estado de venta invalido via CHECK', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0]);

    $threw = false;
    try {
        DB::table('ventas')->where('id', $venta->id)->update(['estado' => 'PENDIENTE_COBRO']);
    } catch (Illuminate\Database\QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
});

/**
 * =========================
 * DEVUELTO como estado de Item
 * =========================
 */
it('el estado DEVUELTO no es vendible en el POS', function () {
    $admin = postventaAdmin();
    $item = Item::create(['estado' => 'DEVUELTO', 'precio' => 100.0]);

    $this->actingAs($admin)
        ->post(route('pos.add'), ['codigo' => $item->codigo])
        ->assertSessionHasErrors('codigo');

    expect(session('pos.cart'))->toBeNull();
});

it('DEVUELTO no admite transiciones manuales (B13: solo revisión formal)', function () {
    expect(Item::canTransition('DEVUELTO', 'VENDIDO'))->toBeFalse();
    expect(Item::canTransition('DEVUELTO', 'RESERVADO'))->toBeFalse();
    expect(Item::canTransition('DEVUELTO', 'DISPONIBLE'))->toBeFalse();
    expect(Item::canTransition('DEVUELTO', 'REPARACION'))->toBeFalse();
    expect(Item::canTransition('DEVUELTO', 'BAJA'))->toBeFalse();
    expect(Item::canTransition('VENDIDO', 'DISPONIBLE'))->toBeFalse();
    expect(Item::canTransition('VENDIDO', 'DEVUELTO'))->toBeFalse();
});

it('el endpoint de cambio de estado manual prohíbe VENDIDO como destino y todo cambio desde DEVUELTO', function () {
    $admin = postventaAdmin();
    $admin->givePermissionTo('items.cambiar_estado');

    $vendido = Item::create(['estado' => 'VENDIDO', 'precio' => 100.0]);
    $devuelto = Item::create(['estado' => 'DEVUELTO', 'precio' => 200.0]);

    $this->actingAs($admin)
        ->post(route('items.changeEstado', $vendido->id), ['estado' => 'DISPONIBLE'])
        ->assertSessionHasErrors('estado');

    $this->actingAs($admin)
        ->post(route('items.changeEstado', $devuelto->id), ['estado' => 'VENDIDO'])
        ->assertSessionHasErrors('estado');

    // B13: toda salida de DEVUELTO (incluida la recepción) pasa por revisión formal.
    $this->actingAs($admin)
        ->post(route('items.changeEstado', $devuelto->id), ['estado' => 'REPARACION'])
        ->assertSessionHasErrors('estado');

    expect($devuelto->refresh()->estado)->toBe('DEVUELTO');
});

/**
 * =========================
 * Vistas y permisos
 * =========================
 */
it('el formulario de cancelacion muestra los items, importes y motivo', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0, 250.5]);

    $this->actingAs($admin)
        ->get(route('ventas.cancelar', $venta))
        ->assertOk()
        ->assertSee('350.50')
        ->assertSee('Cancelar venta');
});

it('el formulario de devolucion muestra solo detalles no devueltos', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0, 250.5]);
    openCajaFor($admin);

    [$detalleDevuelto, $detalleRestante] = $venta->detalles()->orderBy('id')->get();

    $this->actingAs($admin)->post(route('ventas.devolver.store', $venta), [
        'detalles' => [$detalleDevuelto->id],
        'motivo' => 'Primera devolucion parcial.',
        'forma_reembolso' => 'EFECTIVO',
    ])->assertRedirect();

    $this->actingAs($admin)
        ->get(route('ventas.devolver', $venta))
        ->assertOk()
        ->assertSee($detalleRestante->item->codigo)
        ->assertDontSee($detalleDevuelto->item->codigo);
});

it('el detalle y el comprobante imprimible de un documento postventa responden', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0]);
    openCajaFor($admin);

    $this->actingAs($admin)->post(route('ventas.devolver.store', $venta), [
        'detalles' => [$venta->detalles()->first()->id],
        'motivo' => 'Devolucion para comprobante.',
        'forma_reembolso' => 'EFECTIVO',
    ]);

    $doc = DocumentoPostventa::first();

    $this->actingAs($admin)->get(route('postventa.show', $doc))->assertOk();
    $this->actingAs($admin)->get(route('postventa.print', $doc))
        ->assertOk()
        ->assertSee($doc->folio)
        ->assertSee('Total');
});

it('el historial de la venta enlaza los documentos postventa', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([100.0, 250.5]);

    $this->actingAs($admin)->post(route('ventas.devolver.store', $venta), [
        'detalles' => [$venta->detalles()->first()->id],
        'motivo' => 'Devolucion parcial con historial.',
        'forma_reembolso' => 'OTRO',
    ])->assertRedirect();

    $doc = DocumentoPostventa::first();

    $this->actingAs($admin)
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee($doc->folio);
});

it('el rol Ventas devuelve y el rol Auditor solo consulta (cubierto en la matriz)', function () {
    $venta = ventaVendida([100.0]);

    $ventas = User::factory()->create();
    $ventas->givePermissionTo('ventas.devolver');
    $this->actingAs($ventas)
        ->get(route('ventas.devolver', $venta))
        ->assertOk();
    $this->actingAs($ventas)
        ->get(route('ventas.cancelar', $venta))
        ->assertForbidden();

    $auditor = User::factory()->create();
    $auditor->givePermissionTo('ventas.ver');
    $this->actingAs($auditor)
        ->get(route('ventas.cancelar', $venta))
        ->assertForbidden();
    $this->actingAs($auditor)
        ->get(route('ventas.devolver', $venta))
        ->assertForbidden();
});

it('DEVUELTO aparece en el listado de items con su contador', function () {
    Item::create(['estado' => 'DEVUELTO', 'precio' => 100.0]);

    $admin = postventaAdmin();

    $this->actingAs($admin)
        ->get(route('items.index'))
        ->assertOk()
        ->assertSee('DEVUELTO');
});

/**
 * =========================
 * Actor histórico (UserController::destroy)
 * =========================
 */
it('no elimina al usuario actor de un documento postventa y el documento persiste', function () {
    // Actor de la devolución: SIEMPRE genera al menos un Movimiento y un DocumentoPostventa.
    $actor = User::factory()->create();
    $actor->givePermissionTo('ventas.devolver', 'ventas.ver');

    $venta = ventaVendida([100.0]);
    openCajaFor($actor);

    $this->actingAs($actor)->post(route('ventas.devolver.store', $venta), [
        'detalles' => [$venta->detalles()->first()->id],
        'motivo' => 'Devolucion del equipo por el actor.',
        'forma_reembolso' => 'EFECTIVO',
    ])->assertRedirect();

    $doc = DocumentoPostventa::first();
    expect($doc->user_id)->toBe($actor->id);
    $this->assertDatabaseCount('documentos_postventa', 1);

    // Admin intenta eliminar al actor de la postventa.
    Permission::findOrCreate('usuarios.eliminar', 'web');
    $admin = postventaAdmin();
    $admin->givePermissionTo('usuarios.eliminar');

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $actor))
        ->assertSessionHas('error', 'No se puede eliminar este usuario porque tiene documentos postventa registrados.');

    // El usuario y el documento siguen disponibles (respuesta controlada, sin 500).
    $this->assertDatabaseHas('users', ['id' => $actor->id]);
    $this->assertDatabaseCount('documentos_postventa', 1);
    expect(DocumentoPostventa::find($doc->id)->user_id)->toBe($actor->id);
});

/**
 * =========================
 * B14.2 — REEMBOLSO POR FORMA ORIGINAL DE PAGO
 * =========================
 */
it('B14.2 cancela venta mixta devolviendo exactamente por los pagos originales', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([10.00]);
    $sesion = openCajaFor($admin);

    $venta->update(['forma_pago' => 'MIXTO']);

    $efectivo = \App\Models\PagoVenta::create([
        'venta_id' => $venta->id,
        'sesion_caja_id' => $sesion->id,
        'user_id' => $admin->id,
        'metodo' => \App\Models\PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '8.00',
        'efectivo_recibido' => '10.00',
        'cambio_entregado' => '2.00',
        'referencia' => null,
        'origen' => \App\Models\PagoVenta::ORIGEN_POS,
        'orden' => 1,
    ]);

    $tarjeta = \App\Models\PagoVenta::create([
        'venta_id' => $venta->id,
        'sesion_caja_id' => $sesion->id,
        'user_id' => $admin->id,
        'metodo' => \App\Models\PagoVenta::METODO_TARJETA,
        'monto_aplicado' => '2.00',
        'efectivo_recibido' => null,
        'cambio_entregado' => null,
        'referencia' => 'COBRO-ORIGINAL',
        'origen' => \App\Models\PagoVenta::ORIGEN_POS,
        'orden' => 2,
    ]);

    $response = $this->actingAs($admin)
        ->post(route('ventas.cancelar.store', $venta), [
            'motivo' => 'Cancelación total de prueba mixta.',
            'referencias_reembolso' => [
                $tarjeta->id => 'DEV-TARJ-0001',
            ],
        ]);

    $response->assertSessionHasNoErrors();

    $documento = \App\Models\DocumentoPostventa::latest('id')->firstOrFail();

    expect($documento->forma_reembolso)->toBeNull();

    $reembolsos = \App\Models\ReembolsoPostventa::query()
        ->where('documento_postventa_id', $documento->id)
        ->orderBy('orden')
        ->get();

    expect($reembolsos)->toHaveCount(2);

    expect($reembolsos[0]->pago_venta_id)->toBe($efectivo->id);
    expect($reembolsos[0]->metodo)->toBe('EFECTIVO');
    expect($reembolsos[0]->monto)->toBe('8.00');

    expect($reembolsos[1]->pago_venta_id)->toBe($tarjeta->id);
    expect($reembolsos[1]->metodo)->toBe('TARJETA');
    expect($reembolsos[1]->monto)->toBe('2.00');
    expect($reembolsos[1]->referencia)->toBe('DEV-TARJ-0001');

    $movimientoCaja = \App\Models\MovimientoCaja::query()
        ->where('documento_postventa_id', $documento->id)
        ->where('tipo', \App\Models\MovimientoCaja::TIPO_REEMBOLSO_EFECTIVO)
        ->first();

    expect($movimientoCaja)->not->toBeNull();
    expect($movimientoCaja->monto)->toBe('8.00');
    expect($movimientoCaja->pago_venta_id)->toBe($efectivo->id);
});

it('B14.2 prorratea una devolucion parcial 80 20 y conserva trazabilidad', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([4.00, 6.00]);
    $sesion = openCajaFor($admin);

    $venta->update(['forma_pago' => 'MIXTO']);

    $efectivo = \App\Models\PagoVenta::create([
        'venta_id' => $venta->id,
        'sesion_caja_id' => $sesion->id,
        'user_id' => $admin->id,
        'metodo' => \App\Models\PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '8.00',
        'efectivo_recibido' => '8.00',
        'cambio_entregado' => '0.00',
        'referencia' => null,
        'origen' => \App\Models\PagoVenta::ORIGEN_POS,
        'orden' => 1,
    ]);

    $tarjeta = \App\Models\PagoVenta::create([
        'venta_id' => $venta->id,
        'sesion_caja_id' => $sesion->id,
        'user_id' => $admin->id,
        'metodo' => \App\Models\PagoVenta::METODO_TARJETA,
        'monto_aplicado' => '2.00',
        'efectivo_recibido' => null,
        'cambio_entregado' => null,
        'referencia' => 'COBRO-TARJETA',
        'origen' => \App\Models\PagoVenta::ORIGEN_POS,
        'orden' => 2,
    ]);

    $detalle = $venta->detalles()->orderBy('id')->firstOrFail();

    $this->actingAs($admin)
        ->post(route('ventas.devolver.store', $venta), [
            'detalles' => [$detalle->id],
            'motivo' => 'Devolución parcial B14.2.',
            'referencias_reembolso' => [
                $tarjeta->id => 'DEV-TARJ-PARCIAL-1',
            ],
        ])
        ->assertSessionHasNoErrors();

    $documento = \App\Models\DocumentoPostventa::latest('id')->firstOrFail();

    $reembolsos = \App\Models\ReembolsoPostventa::query()
        ->where('documento_postventa_id', $documento->id)
        ->orderBy('orden')
        ->get()
        ->keyBy('pago_venta_id');

    expect($reembolsos[$efectivo->id]->monto)->toBe('3.20');
    expect($reembolsos[$tarjeta->id]->monto)->toBe('0.80');

    $movimientoCaja = \App\Models\MovimientoCaja::query()
        ->where('documento_postventa_id', $documento->id)
        ->where('tipo', \App\Models\MovimientoCaja::TIPO_REEMBOLSO_EFECTIVO)
        ->firstOrFail();

    expect($movimientoCaja->monto)->toBe('3.20');
});

it('B14.2 varias devoluciones terminan exactamente en la composicion original', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([4.00, 6.00]);
    $sesion = openCajaFor($admin);

    $venta->update(['forma_pago' => 'MIXTO']);

    $efectivo = \App\Models\PagoVenta::create([
        'venta_id' => $venta->id,
        'sesion_caja_id' => $sesion->id,
        'user_id' => $admin->id,
        'metodo' => \App\Models\PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '8.00',
        'efectivo_recibido' => '8.00',
        'cambio_entregado' => '0.00',
        'referencia' => null,
        'origen' => \App\Models\PagoVenta::ORIGEN_POS,
        'orden' => 1,
    ]);

    $tarjeta = \App\Models\PagoVenta::create([
        'venta_id' => $venta->id,
        'sesion_caja_id' => $sesion->id,
        'user_id' => $admin->id,
        'metodo' => \App\Models\PagoVenta::METODO_TARJETA,
        'monto_aplicado' => '2.00',
        'efectivo_recibido' => null,
        'cambio_entregado' => null,
        'referencia' => 'COBRO-TARJETA',
        'origen' => \App\Models\PagoVenta::ORIGEN_POS,
        'orden' => 2,
    ]);

    $detalles = $venta->detalles()->orderBy('id')->get();

    $this->actingAs($admin)
        ->post(route('ventas.devolver.store', $venta), [
            'detalles' => [$detalles[0]->id],
            'motivo' => 'Primera devolución B14.2.',
            'referencias_reembolso' => [
                $tarjeta->id => 'DEV-PARCIAL-A',
            ],
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->post(route('ventas.devolver.store', $venta->fresh()), [
            'detalles' => [$detalles[1]->id],
            'motivo' => 'Segunda devolución B14.2.',
            'referencias_reembolso' => [
                $tarjeta->id => 'DEV-PARCIAL-B',
            ],
        ])
        ->assertSessionHasNoErrors();

    $totalEfectivo = \App\Models\ReembolsoPostventa::query()
        ->where('pago_venta_id', $efectivo->id)
        ->get()
        ->sum(fn ($r) => \App\Support\Money::aCentavos($r->monto));

    $totalTarjeta = \App\Models\ReembolsoPostventa::query()
        ->where('pago_venta_id', $tarjeta->id)
        ->get()
        ->sum(fn ($r) => \App\Support\Money::aCentavos($r->monto));

    expect($totalEfectivo)->toBe(800);
    expect($totalTarjeta)->toBe(200);
    expect($totalEfectivo + $totalTarjeta)->toBe(1000);
});

it('B14.2 exige referencia para revertir tarjeta', function () {
    $admin = postventaAdmin();
    $venta = ventaVendida([10.00]);

    $tarjeta = \App\Models\PagoVenta::create([
        'venta_id' => $venta->id,
        'sesion_caja_id' => null,
        'user_id' => $admin->id,
        'metodo' => \App\Models\PagoVenta::METODO_TARJETA,
        'monto_aplicado' => '10.00',
        'efectivo_recibido' => null,
        'cambio_entregado' => null,
        'referencia' => 'COBRO-TARJETA',
        'origen' => \App\Models\PagoVenta::ORIGEN_POS,
        'orden' => 1,
    ]);

    $this->actingAs($admin)
        ->post(route('ventas.cancelar.store', $venta), [
            'motivo' => 'Cancelación electrónica sin referencia.',
        ])
        ->assertSessionHasErrors('reembolso');

    expect(
        \App\Models\ReembolsoPostventa::where(
            'pago_venta_id',
            $tarjeta->id
        )->exists()
    )->toBeFalse();

    expect($venta->fresh()->estado)->toBe(\App\Models\Venta::ESTADO_ACTIVA);
});
