<?php

use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\DocumentoPostventa;
use App\Models\Item;
use App\Models\MovimientoCaja;
use App\Models\MovimientoCxC;
use App\Models\PagoVenta;
use App\Models\ReembolsoPostventa;
use App\Models\User;
use App\Models\Venta;
use App\Services\CajaService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['ventas.ver', 'ventas.crear', 'ventas.cancelar', 'ventas.devolver'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function creditoSeller(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['ventas.ver', 'ventas.crear', 'ventas.cancelar', 'ventas.devolver']);

    return $user;
}

function creditoItem(float $precio = 100.0): Item
{
    return Item::create(['estado' => 'DISPONIBLE', 'precio' => $precio]);
}

function creditoCliente(
    bool $habilitado = true,
    string $limite = '1000.00',
    ?int $dias = 30
): Cliente {
    return Cliente::create([
        'nombre' => 'Cliente Crédito POS',
        'credito_habilitado' => $habilitado,
        'limite_credito' => $limite,
        'dias_credito' => $dias,
    ]);
}

function creditoSession(Item|array $items, Cliente $cliente): void
{
    $ids = $items instanceof Item ? [$items->id] : collect($items)->map(fn ($i) => $i->id)->all();

    test()->session([
        'pos.cart' => $ids,
        'pos.cliente_id' => $cliente->id,
    ]);
}

/**
 * Suma en centavos de todos los PagoVenta reales, sin recurrir a float:
 * se convierte cada monto_aplicado (decimal persistido) con Money::aCentavos.
 */
function sumaPagosRealesCentavos(): int
{
    return PagoVenta::query()
        ->get()
        ->sum(
            fn (PagoVenta $p) => Money::aCentavos((string) $p->monto_aplicado)
        );
}

/**
 * =========================
 * Casos CONTADO (regresión B14)
 * =========================
 */
it('contado EFECTIVO actual sigue funcionando', function () {
    $user = creditoSeller();
    $item = creditoItem(200.0);
    $cliente = creditoCliente();

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), array_merge([
        'items' => [$item->id],
    ], pagosEfectivo(200.0), ['credito_monto' => null]));

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('pagos_venta', 1);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);

    $venta = Venta::first();
    expect($venta->forma_pago)->toBe('EFECTIVO');
    expect((string) $venta->total)->toBe('200.00');
});

it('contado TARJETA sigue funcionando', function () {
    $user = creditoSeller();
    $item = creditoItem(150.0);
    $cliente = creditoCliente();

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), array_merge([
        'items' => [$item->id],
    ], pagosMetodo(150.0, 'TARJETA', 'TRX-A'), ['credito_monto' => null]));

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);

    $venta = Venta::first();
    expect($venta->forma_pago)->toBe('TARJETA');
    expect(PagoVenta::first()->metodo)->toBe('TARJETA');
});

it('contado combinado (múltiples métodos reales) sigue funcionando', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente();

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), array_merge([
        'items' => [$item->id],
    ], pagosMixtos(500.0, 300.0, 'TARJETA', 200.0), ['credito_monto' => null]));

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);

    $venta = Venta::first();
    expect($venta->forma_pago)->toBe('MIXTO');
    expect(sumaPagosRealesCentavos())->toBe(50000);
});

/**
 * =========================
 * CRÉDITO TOTAL
 * =========================
 */
it('crédito total: Venta CREDITO, 0 PagoVenta, 0 MovimientoCaja, 1 CxC CARGO_INICIAL', function () {
    $user = creditoSeller();
    $item = creditoItem(1000.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    $sesion = openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'credito_monto' => '1000.00',
    ]);

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('venta_detalles', 1);
    $this->assertDatabaseCount('pagos_venta', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 1);
    $this->assertDatabaseCount('movimientos_caja', 0);

    $venta = Venta::first();
    expect($venta->forma_pago)->toBe('CREDITO');
    expect((string) $venta->total)->toBe('1000.00');

    $cxc = CuentaPorCobrar::first();
    expect($cxc->venta_id)->toBe($venta->id);
    expect($cxc->cliente_id)->toBe($cliente->id);
    expect($cxc->importe_original_centavos)->toBe(100000);
    expect($cxc->saldo_centavos)->toBe(100000);
    expect($cxc->estado)->toBe(CuentaPorCobrar::ESTADO_PENDIENTE);

    $this->assertDatabaseCount('movimientos_cxc', 1);
    expect(MovimientoCxC::first()->tipo)->toBe(MovimientoCxC::TIPO_CARGO_INICIAL);
    expect(MovimientoCxC::first()->monto_centavos)->toBe(100000);

    expect($item->refresh()->estado)->toBe('VENDIDO');

    // Caja física SOLO ve dinero real: la sesión queda sin movimientos.
    expect(MovimientoCaja::where('sesion_caja_id', $sesion->id)->count())->toBe(0);
});

/**
 * =========================
 * CRÉDITO PARCIAL + EFECTIVO
 * =========================
 */
it('crédito parcial + efectivo: PagoVenta real, CxC financiado, MIXTO, caja solo efectivo', function () {
    $user = creditoSeller();
    $item = creditoItem(1000.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    // TOTAL 1000; real 400 (recibido 500) -> cambio 100; crédito 600.
    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'pagos' => [[
            'metodo' => 'EFECTIVO',
            'monto_aplicado' => '400.00',
            'efectivo_recibido' => '500.00',
        ]],
        'credito_monto' => '600.00',
    ]);

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('pagos_venta', 1);
    $this->assertDatabaseCount('cuentas_por_cobrar', 1);

    $venta = Venta::first();
    expect($venta->forma_pago)->toBe('MIXTO');
    expect((string) $venta->total)->toBe('1000.00');

    $pago = PagoVenta::first();
    expect($pago->metodo)->toBe('EFECTIVO');
    expect((string) $pago->monto_aplicado)->toBe('400.00');
    expect((string) $pago->efectivo_recibido)->toBe('500.00');
    expect((string) $pago->cambio_entregado)->toBe('100.00');

    $cxc = CuentaPorCobrar::first();
    expect($cxc->importe_original_centavos)->toBe(60000);
    expect($cxc->saldo_centavos)->toBe(60000);

    // Movimientos de caja: COBRO_EFECTIVO +500 y CAMBIO_ENTREGADO -100.
    $movs = MovimientoCaja::all();
    expect($movs->count())->toBe(2);
    $cobro = $movs->firstWhere('tipo', MovimientoCaja::TIPO_COBRO_EFECTIVO);
    $cambio = $movs->firstWhere('tipo', MovimientoCaja::TIPO_CAMBIO_ENTREGADO);
    expect($cobro->monto)->toBe('500.00');
    expect($cobro->direccion)->toBe(MovimientoCaja::DIR_ENTRADA);
    expect($cambio->monto)->toBe('100.00');
    expect($cambio->direccion)->toBe(MovimientoCaja::DIR_SALIDA);

    // Invariante: real + CxC = total.
    expect(sumaPagosRealesCentavos() + $cxc->importe_original_centavos)->toBe(100000);
});

/**
 * =========================
 * CRÉDITO PARCIAL + TARJETA
 * =========================
 */
it('crédito parcial + tarjeta: PagoVenta real, CxC correcto, 0 MovimientoCaja, MIXTO', function () {
    $user = creditoSeller();
    $item = creditoItem(1000.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'pagos' => [[
            'metodo' => 'TARJETA',
            'monto_aplicado' => '400.00',
            'referencia' => 'TRX-CARD',
        ]],
        'credito_monto' => '600.00',
    ]);

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('pagos_venta', 1);
    $this->assertDatabaseCount('cuentas_por_cobrar', 1);
    $this->assertDatabaseCount('movimientos_caja', 0);

    $venta = Venta::first();
    expect($venta->forma_pago)->toBe('MIXTO');
    expect(PagoVenta::first()->metodo)->toBe('TARJETA');
    expect(CuentaPorCobrar::first()->importe_original_centavos)->toBe(60000);
});

/**
 * =========================
 * CRÉDITO PARCIAL + MÚLTIPLES MÉTODOS REALES
 * =========================
 */
it('crédito parcial + múltiples métodos reales', function () {
    $user = creditoSeller();
    $item = creditoItem(1000.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    // real: 200 efectivo + 200 tarjeta = 400; crédito 600.
    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'pagos' => [
            ['metodo' => 'EFECTIVO', 'monto_aplicado' => '200.00', 'efectivo_recibido' => '200.00'],
            ['metodo' => 'TARJETA', 'monto_aplicado' => '200.00', 'referencia' => 'TRX-M'],
        ],
        'credito_monto' => '600.00',
    ]);

    $this->assertDatabaseCount('pagos_venta', 2);
    $this->assertDatabaseCount('cuentas_por_cobrar', 1);

    $venta = Venta::first();
    expect($venta->forma_pago)->toBe('MIXTO');
    expect(sumaPagosRealesCentavos())->toBe(40000);
    expect(CuentaPorCobrar::first()->importe_original_centavos)->toBe(60000);
});

/**
 * =========================
 * credito_monto = 0 preserva B14 (pagos obligatorios)
 * =========================
 */
it('credito_monto = 0 preserva el comportamiento B14 (pagos cubren el total)', function () {
    $user = creditoSeller();
    $item = creditoItem(100.0);
    $cliente = creditoCliente();

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), array_merge([
        'items' => [$item->id],
    ], pagosEfectivo(100.0), ['credito_monto' => '0']));

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('pagos_venta', 1);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    expect(Venta::first()->forma_pago)->toBe('EFECTIVO');
});

it('credito_monto vacío se trata como SIN crédito', function () {
    $user = creditoSeller();
    $item = creditoItem(100.0);
    $cliente = creditoCliente();

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), array_merge([
        'items' => [$item->id],
    ], pagosEfectivo(100.0), ['credito_monto' => '']));

    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    expect(Venta::first()->forma_pago)->toBe('EFECTIVO');
});

it('credito_monto "0" -> sin crédito', function () {
    $user = creditoSeller();
    $item = creditoItem(100.0);
    $cliente = creditoCliente();

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), array_merge([
        'items' => [$item->id],
    ], pagosEfectivo(100.0), ['credito_monto' => '0']));

    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    expect(Venta::first()->forma_pago)->toBe('EFECTIVO');
});

it('credito_monto "0.0" -> sin crédito', function () {
    $user = creditoSeller();
    $item = creditoItem(100.0);
    $cliente = creditoCliente();

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), array_merge([
        'items' => [$item->id],
    ], pagosEfectivo(100.0), ['credito_monto' => '0.0']));

    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    expect(Venta::first()->forma_pago)->toBe('EFECTIVO');
});

it('credito_monto "0.00" -> sin crédito', function () {
    $user = creditoSeller();
    $item = creditoItem(100.0);
    $cliente = creditoCliente();

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), array_merge([
        'items' => [$item->id],
    ], pagosEfectivo(100.0), ['credito_monto' => '0.00']));

    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    expect(Venta::first()->forma_pago)->toBe('EFECTIVO');
});

it('credito_monto "1.23" -> exactamente 123 centavos (MIXTO)', function () {
    $user = creditoSeller();
    $item = creditoItem(100.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    // Total 100 con crédito 1.23 -> real esperado 98.77.
    $this->actingAs($user)->post(route('pos.checkout'), array_merge([
        'items' => [$item->id],
    ], pagosEfectivo(98.77), ['credito_monto' => '1.23']));

    $venta = Venta::first();
    expect($venta->forma_pago)->toBe('MIXTO');
    expect(CuentaPorCobrar::first()->importe_original_centavos)->toBe(123);

    $sumaPagos = sumaPagosRealesCentavos();
    expect($sumaPagos + 123)->toBe(Money::aCentavos($venta->total));
});

it('credito_monto "1.234" -> ValidationException controlada (no 500)', function () {
    $user = creditoSeller();
    $item = creditoItem(100.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'credito_monto' => '1.234',
    ])
        ->assertSessionHasErrors('credito_monto')
        ->assertSessionHasErrors('credito_monto', 'El monto a crédito debe ser un importe válido con máximo 2 decimales.');

    $this->assertDatabaseCount('ventas', 0);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
});

it('credito_monto formato científico "1e2" -> error controlado de credito_monto (no 500)', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'credito_monto' => '1e2',
    ])
        ->assertSessionHasErrors('credito_monto');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
});

// FIX 1 (REV2): barrera HTTP max:9999999999.99 ANTES de Money. Un monto
// extremadamente grande se rechaza de forma controlada, sin Venta/CxC y sin 500.
it('credito_monto extremadamente grande -> rechazado de forma controlada', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'credito_monto' => '20000000000.00',
    ])
        ->assertSessionHasErrors('credito_monto');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
});

it('credito_monto 9999999999.99 pasa la barrera de precisión (max) sin fallar por ella', function () {
    $user = creditoSeller();
    $item = creditoItem(100.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    // Pasa la validación de precisión HTTP (max:9999999999.99); el rechazo debe
    // venir de la regla de negocio (excede el total), NO de la regla max.
    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'credito_monto' => '9999999999.99',
    ])
        ->assertSessionHasErrors('credito_monto', 'El monto a crédito no puede exceder el total de la venta.');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
});

/**
 * =========================
 * Validaciones de cobertura (rechazadas, rollback)
 * =========================
 */
it('crédito mayor que total es rechazado', function () {
    $user = creditoSeller();
    $item = creditoItem(100.0);
    $cliente = creditoCliente(limite: '2000.00');

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'credito_monto' => '150.00',
        ])
        ->assertSessionHasErrors('credito_monto');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
});

it('crédito negativo rechazado', function () {
    $user = creditoSeller();
    $item = creditoItem(100.0);
    $cliente = creditoCliente(limite: '2000.00');

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'credito_monto' => '-5.00',
        ])
        ->assertSessionHasErrors('credito_monto');

    $this->assertDatabaseCount('ventas', 0);
});

it('real más crédito no cubre el total: rechazado', function () {
    $user = creditoSeller();
    $item = creditoItem(1000.0);
    $cliente = creditoCliente(limite: '2000.00');

    creditoSession($item, $cliente);
    openCajaFor($user);

    // real 300 + crédito 600 = 900 < 1000
    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'pagos' => [['metodo' => 'EFECTIVO', 'monto_aplicado' => '300.00', 'efectivo_recibido' => '300.00']],
            'credito_monto' => '600.00',
        ])
        ->assertSessionHasErrors('pagos');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
});

it('real más crédito supera el total: rechazado sin rollback parcial', function () {
    $user = creditoSeller();
    $item = creditoItem(1000.0);
    $cliente = creditoCliente(limite: '2000.00');

    creditoSession($item, $cliente);
    openCajaFor($user);

    // real 500 + crédito 600 = 1100 > 1000
    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'pagos' => [['metodo' => 'EFECTIVO', 'monto_aplicado' => '500.00', 'efectivo_recibido' => '500.00']],
            'credito_monto' => '600.00',
        ])
        ->assertSessionHasErrors('pagos');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
});

it('pagos vacíos con crédito parcial rechazados', function () {
    $user = creditoSeller();
    $item = creditoItem(1000.0);
    $cliente = creditoCliente(limite: '2000.00');

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'credito_monto' => '600.00',
        ])
        ->assertSessionHasErrors('pagos');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
});

it('pagos vacíos con crédito total aceptados', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente(limite: '2000.00');

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'credito_monto' => '500.00',
    ]);

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('pagos_venta', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 1);
    expect(Venta::first()->forma_pago)->toBe('CREDITO');
});

it('pagos vacíos sin crédito y total > 0 rechazados', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente();

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'credito_monto' => null,
        ])
        ->assertSessionHasErrors('pagos');

    $this->assertDatabaseCount('ventas', 0);
});

// B15.3 NO introduce ventas gratuitas implícitas: sin crédito, aunque el total
// sea 0, siempre debe enviarse al menos un pago real (semántica B14).
it('item precio 0.00 + credito 0 + pagos vacíos -> rechazado, Item sigue DISPONIBLE', function () {
    $user = creditoSeller();
    $item = creditoItem(0.0);
    $cliente = creditoCliente();

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'credito_monto' => null,
        ])
        ->assertSessionHasErrors('pagos');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('venta_detalles', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
});

// Contraste: crédito total > 0 con pagos vacíos SÍ es válido.
it('crédito total > 0 con pagos vacíos continúa siendo válido', function () {
    $user = creditoSeller();
    $item = creditoItem(0.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    // Item 0.00: crédito 0 equivale a total 0 sin crédito -> rechazado.
    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'credito_monto' => '0',
        ])
        ->assertSessionHasErrors('pagos');

    // Con un item de precio real y crédito total, pagos vacíos es válido.
    $item2 = creditoItem(500.0);
    creditoSession($item2, $cliente);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item2->id],
            'credito_monto' => '500.00',
        ])
        ->assertRedirect();

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('pagos_venta', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 1);
    expect(Venta::first()->forma_pago)->toBe('CREDITO');
});

/**
 * =========================
 * Rollback CxC (autoridad CuentaPorCobrarService)
 * =========================
 */
it('cliente sin credito_habilitado hace rollback completo', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente(habilitado: false);

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'credito_monto' => '500.00',
        ])
        ->assertSessionHasErrors('credito_monto');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('venta_detalles', 0);
    $this->assertDatabaseCount('pagos_venta', 0);
    $this->assertDatabaseCount('movimientos_caja', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    $this->assertDatabaseCount('movimientos_cxc', 0);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
});

it('límite insuficiente hace rollback completo', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente(limite: '200.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'credito_monto' => '500.00',
        ])
        ->assertSessionHasErrors('credito_monto');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    $this->assertDatabaseCount('movimientos', 0);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
});

// Cobertura REAL: un cliente con configuración de crédito incompleta/inválida
// (crédito no habilitado y sin días) es rechazado al intentar financiar.
// NOTA: la validación de dias_credito como constraint vive en B15.1 / pruebas
// de BD. Este caso no fuerza saltarse CHECKs: sólo afirmamos que el checkout
// con dicha configuración es rechazado y revierte por completo.
it('cliente con configuración de crédito inválida (no habilitado) hace rollback completo', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);

    $cliente = Cliente::create([
        'nombre' => 'Cliente Config Crédito Inválida',
        'credito_habilitado' => false,
        'limite_credito' => '1000.00',
        'dias_credito' => null,
    ]);

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'credito_monto' => '500.00',
        ])
        ->assertSessionHasErrors('credito_monto');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
});

it('exposición previa + nuevo crédito excede límite hace rollback completo', function () {
    $user = creditoSeller();
    $item = creditoItem(3000.0);
    $cliente = creditoCliente(limite: '4000.00', dias: 30);

    // Exposición previa: una CxC de 2500 ya viva.
    $ventaPrevia = Venta::create([
        'user_id' => $user->id,
        'cliente_id' => $cliente->id,
        'total' => '2500.00',
        'forma_pago' => 'CREDITO',
    ]);
    $cxcPrevia = CuentaPorCobrar::create([
        'venta_id' => $ventaPrevia->id,
        'cliente_id' => $cliente->id,
        'importe_original_centavos' => 250000,
        'saldo_centavos' => 250000,
        'dias_credito_aplicados' => 30,
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'estado' => CuentaPorCobrar::ESTADO_PENDIENTE,
    ]);
    MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cxcPrevia->id,
        'user_id' => $user->id,
        'tipo' => MovimientoCxC::TIPO_CARGO_INICIAL,
        'monto_centavos' => 250000,
        'saldo_antes_centavos' => 0,
        'saldo_despues_centavos' => 250000,
        'metodo' => null,
        'referencia' => null,
        'movimiento_origen_id' => null,
    ]);

    // Nuevo crédito 2000 + real 1000 (total 3000) -> exposición 2500 + 2000 = 4500 > 4000 -> rechazado.
    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'pagos' => [['metodo' => 'EFECTIVO', 'monto_aplicado' => '1000.00', 'efectivo_recibido' => '1000.00']],
            'credito_monto' => '2000.00',
        ])
        ->assertSessionHasErrors('credito_monto');

    $this->assertDatabaseCount('ventas', 1); // solo la previa
    $this->assertDatabaseCount('cuentas_por_cobrar', 1); // solo la previa
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
});

it('request manipulado con metodo=CREDITO en pagos es rechazado', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente(limite: '2000.00');

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'pagos' => [['metodo' => 'CREDITO', 'monto_aplicado' => '500.00']],
            'credito_monto' => null,
        ])
        ->assertSessionHasErrors('pagos.0.metodo');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('pagos_venta', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
});

/**
 * =========================
 * Sesión de caja B14.3.1 incluso en crédito total
 * =========================
 */
it('crédito total SIN sesión de caja abierta es rechazado (no Venta ni CxC)', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente(limite: '2000.00');

    creditoSession($item, $cliente);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'credito_monto' => '500.00',
        ])
        ->assertSessionHasErrors('caja');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
});

it('crédito total conserva el requisito B14.3.1 de caja asignada', function () {
    $user = creditoSeller();
    $other = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente(limite: '2000.00');

    creditoSession($item, $cliente);

    // El usuario abre su caja asignada (B14.3.1).
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'credito_monto' => '500.00',
        ])
        ->assertRedirect();

    $this->assertDatabaseCount('ventas', 1);
    $this->assertDatabaseCount('cuentas_por_cobrar', 1);

    // Otro usuario sin caja abierta no puede vender a crédito.
    $otherItem = creditoItem(300.0);
    creditoSession($otherItem, $cliente);

    $this->actingAs($other)
        ->post(route('pos.checkout'), [
            'items' => [$otherItem->id],
            'credito_monto' => '300.00',
        ])
        ->assertSessionHasErrors('caja');

    $this->assertDatabaseCount('cuentas_por_cobrar', 1);
});

/**
 * =========================
 * CajaService::cobrarVenta generalizado
 * =========================
 */
it('CajaService: pagos=[] + importe real esperado 0 -> válido (0 PagoVenta/0 Movimiento)', function () {
    $user = creditoSeller();
    $cliente = creditoCliente();
    $venta = Venta::create([
        'user_id' => $user->id,
        'cliente_id' => $cliente->id,
        'total' => '0.00',
        'forma_pago' => 'CREDITO',
    ]);
    $sesion = openCajaFor($user);

    $res = app(CajaService::class)->cobrarVenta($venta, $sesion, $user, [], 0);

    expect($res['pagos']->count())->toBe(0);
    expect($res['movimientos']->count())->toBe(0);
    $this->assertDatabaseCount('pagos_venta', 0);
    $this->assertDatabaseCount('movimientos_caja', 0);
});

it('CajaService: pagos=[] + importe real esperado > 0 -> rechazado', function () {
    $user = creditoSeller();
    $cliente = creditoCliente();
    $venta = Venta::create([
        'user_id' => $user->id,
        'cliente_id' => $cliente->id,
        'total' => '500.00',
        'forma_pago' => 'EFECTIVO',
    ]);
    $sesion = openCajaFor($user);

    expect(fn () => app(CajaService::class)->cobrarVenta($venta, $sesion, $user, [], 500))
        ->toThrow(\DomainException::class, 'No se envió ningún pago real');
});

it('CajaService: rechaza importe real esperado negativo', function () {
    $user = creditoSeller();
    $cliente = creditoCliente();
    $venta = Venta::create([
        'user_id' => $user->id,
        'cliente_id' => $cliente->id,
        'total' => '100.00',
        'forma_pago' => 'EFECTIVO',
    ]);
    $sesion = openCajaFor($user);

    expect(fn () => app(CajaService::class)->cobrarVenta($venta, $sesion, $user, [], -1))
        ->toThrow(\DomainException::class, 'no puede ser negativo');
});

// Hardening: aunque el HTTP ya lo bloquea en validación, el SERVICIO también
// debe rechazar metodo=CREDITO, demostrando que CREDITO NUNCA puede convertirse
// en un PagoVenta (el crédito no forma parte de PagoVenta::METODOS).
it('CajaService: rechaza un pago con metodo CREDITO (no es un pago real)', function () {
    $user = creditoSeller();
    $cliente = creditoCliente();
    $venta = Venta::create([
        'user_id' => $user->id,
        'cliente_id' => $cliente->id,
        'total' => '500.00',
        'forma_pago' => 'CREDITO',
    ]);
    $sesion = openCajaFor($user);

    $pagos = [[
        'metodo' => 'CREDITO',
        'monto_aplicado' => \App\Support\Money::aPrecio(50000),
        'efectivo_recibido' => null,
        'referencia' => null,
    ]];

    expect(fn () => app(CajaService::class)->cobrarVenta($venta, $sesion, $user, $pagos, 50000))
        ->toThrow(\DomainException::class, 'CREDITO');

    $this->assertDatabaseCount('pagos_venta', 0);
    $this->assertDatabaseCount('movimientos_caja', 0);
});

/**
 * =========================
 * Invariante económico + verificación de datos
 * =========================
 */
it('comprobación: SUM(PagoVenta real) + CxC original = Venta.total', function () {
    $user = creditoSeller();
    $item = creditoItem(1000.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'pagos' => [['metodo' => 'TARJETA', 'monto_aplicado' => '300.00', 'referencia' => 'X']],
        'credito_monto' => '700.00',
    ]);

    $venta = Venta::first();
    $cxc = CuentaPorCobrar::first();

    $sumaRealesCentavos = sumaPagosRealesCentavos();
    expect($sumaRealesCentavos + $cxc->importe_original_centavos)->toBe(Money::aCentavos($venta->total));

    // MIXTO: SUM pagos = 300, CxC original = 700, 300 + 700 = 1000.
    expect($sumaRealesCentavos)->toBe(30000);
    expect($cxc->importe_original_centavos)->toBe(70000);
    expect($venta->forma_pago)->toBe('MIXTO');
});

it('MIXTO verificación de datos: ventas.total, forma_pago, pagos, CxC, caja', function () {
    $user = creditoSeller();
    $item = creditoItem(1000.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'pagos' => [['metodo' => 'EFECTIVO', 'monto_aplicado' => '400.00', 'efectivo_recibido' => '400.00']],
        'credito_monto' => '600.00',
    ]);

    $venta = Venta::first();
    $cxc = CuentaPorCobrar::first();

    $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'total' => '1000.00', 'forma_pago' => 'MIXTO']);
    $this->assertDatabaseHas('pagos_venta', ['venta_id' => $venta->id, 'monto_aplicado' => '400.00']);
    $this->assertDatabaseHas('cuentas_por_cobrar', ['venta_id' => $venta->id, 'importe_original_centavos' => 60000, 'saldo_centavos' => 60000]);

    $sumaPagos = sumaPagosRealesCentavos();
    expect($sumaPagos + $cxc->importe_original_centavos)->toBe(100000);
});

/**
 * =========================
 * Ticket / detalle muestran CxC sin inventar PagoVenta
 * =========================
 */
it('ticket y detalle muestran CxC sin inventar PagoVenta (crédito puro)', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'credito_monto' => '500.00',
    ]);

    $venta = Venta::first();
    $cxc = CuentaPorCobrar::first();

    $this->assertDatabaseCount('pagos_venta', 0);

    $this->actingAs($user)
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee($cxc->folio)
        ->assertSee('CREDITO');

    $this->actingAs($user)
        ->get(route('ventas.ticket', $venta))
        ->assertOk()
        ->assertSee($cxc->folio);
});

// B15.3 no debe etiquetar como "a crédito" a ventas históricas/legacy que
// simplemente no tienen desglose de pagos ni CuentaPorCobrar.
it('venta legacy (sin PagoVenta y sin CxC) NO se etiqueta como crédito en detalle', function () {
    $user = creditoSeller();
    $cliente = creditoCliente();

    $venta = Venta::create([
        'user_id' => $user->id,
        'cliente_id' => $cliente->id,
        'cliente_codigo' => $cliente->codigo,
        'cliente_nombre' => $cliente->nombre,
        'total' => '500.00',
        'forma_pago' => 'EFECTIVO',
    ]);

    $this->assertDatabaseCount('pagos_venta', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);

    $this->actingAs($user)
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertDontSee('venta 100% a crédito')
        ->assertSee('Venta histórica / legacy sin desglose de pagos.');
});

// Contraste: venta crédito puro (0 PagoVenta, 1 CxC) SÍ muestra el aviso de crédito.
it('venta crédito puro si muestra aviso de crédito en detalle', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'credito_monto' => '500.00',
    ]);

    $venta = Venta::first();

    $this->assertDatabaseCount('pagos_venta', 0);
    $this->assertDatabaseCount('cuentas_por_cobrar', 1);

    $this->actingAs($user)
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee('venta 100% a crédito');
});

/**
 * =========================
 * Postventa debt-first con CxC (B15.5)
 * =========================
 */
it('Postventa cancelar venta crédito puro: CANCELADA, deuda absorbida, sin reembolso monetario', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente(limite: '2000.00');

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'credito_monto' => '500.00',
    ]);

    $venta = Venta::first();
    $this->assertDatabaseCount('pagos_venta', 0); // crédito puro
    $this->assertDatabaseCount('cuentas_por_cobrar', 1);

    $this->actingAs($user)
        ->post(route('ventas.cancelar.store', $venta), [
            'motivo' => 'Cancelación de la venta a crédito.',
        ])
        ->assertRedirect();

    $this->assertDatabaseCount('documentos_postventa', 1);
    $this->assertDatabaseCount('reembolsos_postventa', 0);
    $documento = DocumentoPostventa::first();
    expect($documento->tipo)->toBe(DocumentoPostventa::TIPO_CANCELACION);
    expect($documento->total)->toBe('500.00');

    $cxc = CuentaPorCobrar::first();
    expect($cxc->refresh()->saldo_centavos)->toBe(0);
    expect($cxc->estado)->toBe(CuentaPorCobrar::ESTADO_CANCELADA);

    $deuda = MovimientoCxC::where('cuenta_por_cobrar_id', $cxc->id)
        ->where('tipo', MovimientoCxC::TIPO_CANCELACION)
        ->first();
    expect($deuda)->not->toBeNull();
    expect($deuda->monto_centavos)->toBe(50000);
    expect($deuda->documento_postventa_id)->toBe($documento->id);

    expect($venta->refresh()->estado)->toBe(Venta::ESTADO_CANCELADA);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
    $this->assertDatabaseCount('movimientos_caja', 0); // nada que reembolsar
});

it('Postventa devolver venta con crédito parcial: deuda-primero paga el saldo con el medio original', function () {
    $user = creditoSeller();
    $item = creditoItem(1000.0);
    $cliente = creditoCliente(limite: '2000.00');

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'pagos' => [['metodo' => 'EFECTIVO', 'monto_aplicado' => '400.00', 'efectivo_recibido' => '400.00']],
        'credito_monto' => '600.00',
    ]);

    $venta = Venta::first();
    $detalleId = $venta->detalles()->first()->id;

    $this->actingAs($user)
        ->post(route('ventas.devolver.store', $venta), [
            'motivo' => 'Devolución con aplicación deuda-primero.',
            'detalles' => [$detalleId],
        ])
        ->assertRedirect();

    $this->assertDatabaseCount('documentos_postventa', 1);
    $this->assertDatabaseCount('reembolsos_postventa', 1);
    $documento = DocumentoPostventa::first();
    expect($documento->tipo)->toBe(DocumentoPostventa::TIPO_DEVOLUCION);
    expect($documento->total)->toBe('1000.00');

    $cxc = CuentaPorCobrar::first();
    expect($cxc->refresh()->saldo_centavos)->toBe(0); // 60000 - 60000

    $deuda = MovimientoCxC::where('cuenta_por_cobrar_id', $cxc->id)
        ->where('tipo', MovimientoCxC::TIPO_REDUCCION_POSTVENTA)
        ->first();
    expect($deuda)->not->toBeNull();
    expect($deuda->monto_centavos)->toBe(60000);
    expect($deuda->documento_postventa_id)->toBe($documento->id);

    $reembolso = ReembolsoPostventa::first();
    expect($reembolso->origen)->toBe(ReembolsoPostventa::ORIGEN_AUTOMATICO);
    expect($reembolso->metodo)->toBe(PagoVenta::METODO_EFECTIVO);
    expect((string) $reembolso->monto)->toBe('400.00');
    expect((int) $reembolso->pago_venta_id)->toBe((int) $venta->pagos()->first()->id);

    // Caja física: cobro original (400) + reembolso efectivo (400).
    expect(MovimientoCaja::where('sesion_caja_id', openCajaFor($user)->id)->count())->toBe(2);
    expect($venta->refresh()->estado)->toBe(Venta::ESTADO_DEVUELTA);
    expect($item->refresh()->estado)->toBe('DEVUELTO');
});

it('Postventa de venta SIN crédito conserva el comportamiento existente', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente();

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), array_merge([
        'items' => [$item->id],
    ], pagosEfectivo(500.0), ['credito_monto' => null]));

    $venta = Venta::first();
    $this->assertDatabaseCount('cuentas_por_cobrar', 0);

    $this->actingAs($user)
        ->post(route('ventas.cancelar.store', $venta), [
            'motivo' => 'Cancelación legítima, no hay crédito.',
        ])
        ->assertRedirect();

    $this->assertDatabaseCount('documentos_postventa', 1);
    expect($venta->refresh()->estado)->toBe(Venta::ESTADO_CANCELADA);
});

/**
 * =========================
 * Venta::FORMAS_PAGO admite CREDITO; PagoVenta::METODOS NO
 * =========================
 */
it('Venta::FORMAS_PAGO admite CREDITO', function () {
    expect(Venta::FORMAS_PAGO)->toContain('CREDITO');
    expect(Venta::FORMAS_PAGO)->toContain('MIXTO');
    expect(Venta::FORMAS_PAGO)->toContain('OTRO');
});

it('PagoVenta::METODOS NO admite CREDITO (crédito nunca en pagos_venta)', function () {
    expect(PagoVenta::METODOS)->not->toContain('CREDITO');
    expect(PagoVenta::METODOS)->toContain('EFECTIVO');
    expect(PagoVenta::METODOS)->toContain('TARJETA');
    expect(PagoVenta::METODOS)->toContain('TRANSFERENCIA');
});

/**
 * =========================
 * Rollback CxC después de validar Items (Item vuelve a DISPONIBLE)
 * =========================
 */
it('fallo CxC después de validar Items deja el Item DISPONIBLE por rollback', function () {
    $user = creditoSeller();
    $item = creditoItem(500.0);
    $cliente = creditoCliente(limite: '100.00', dias: 30); // límite insuficiente

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('pos.checkout'), [
            'items' => [$item->id],
            'credito_monto' => '500.00',
        ])
        ->assertSessionHasErrors('credito_monto');

    $this->assertDatabaseCount('ventas', 0);
    $this->assertDatabaseCount('movimientos', 0);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
});

/**
 * Caja física SOLO ve dinero real en crédito total: verificar que el cobro
 * real esperado 0 no crea movimientos ni Pagos.
 */
it('MovimientoCaja vinculado a venta es 0 en crédito total', function () {
    $user = creditoSeller();
    $item = creditoItem(1000.0);
    $cliente = creditoCliente(limite: '2000.00', dias: 30);

    creditoSession($item, $cliente);
    openCajaFor($user);

    $this->actingAs($user)->post(route('pos.checkout'), [
        'items' => [$item->id],
        'credito_monto' => '1000.00',
    ]);

    $venta = Venta::first();
    $this->assertDatabaseCount('pagos_venta', 0);
    $this->assertDatabaseCount('movimientos_caja', 0);
    $this->assertDatabaseHas('movimientos_cxc', [
        'tipo' => MovimientoCxC::TIPO_CARGO_INICIAL,
        'monto_centavos' => 100000,
    ]);
});
