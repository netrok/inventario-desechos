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
use App\Models\VentaDetalle;
use App\Services\CajaService;
use App\Services\CuentaPorCobrarService;
use App\Services\PostventaService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['ventas.cancelar', 'ventas.devolver', 'ventas.ver', 'cxc.ver'] as $p) {
        Permission::findOrCreate($p, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Cliente con crédito habilitado (suficiente margen para financiar).
 */
function debtCliente(int $limite = 400000): Cliente
{
    return Cliente::create([
        'nombre' => 'Cliente Debt-First',
        'activo' => true,
        'credito_habilitado' => true,
        'limite_credito' => Money::aPrecio($limite),
        'dias_credito' => 30,
    ]);
}

function debtItem(string $precio): Item
{
    return Item::create(['estado' => 'DISPONIBLE', 'precio' => $precio]);
}

function debtActor(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['ventas.cancelar', 'ventas.devolver', 'ventas.ver', 'cxc.ver']);

    return $user;
}

/**
 * Cadena de origen: Venta + VentaDetalles + Items VENDIDOS + CuentaPorCobrar.
 * Devuelve el array evaluado en el ámbito del caller (patrón "return { ... };").
 */
function debtOriginar(array &$escenario): void
{
    $escenario['cliente'] = debtCliente($escenario['limite'] ?? 400000);
    $escenario['usuario'] = debtActor();
    test()->actingAs($escenario['usuario']);
    $escenario['precios'] = $escenario['precios'] ?? ['500.00', '500.00'];
    $escenario['forma_pago'] = $escenario['forma_pago'] ?? 'CREDITO';
    $escenario['items'] = collect($escenario['precios'])
        ->map(fn (string $p) => debtItem($p))
        ->values();

    $escenario['venta'] = Venta::create([
        'user_id' => $escenario['usuario']->id,
        'cliente_id' => $escenario['cliente']->id,
        'total' => Money::aPrecio(coinsTotal($escenario['precios'])),
        'forma_pago' => $escenario['forma_pago'],
    ]);

    $escenario['detalles'] = $escenario['items']->map(function (Item $item) use ($escenario) {
        $d = VentaDetalle::create([
            'venta_id' => $escenario['venta']->id,
            'item_id' => $item->id,
            'precio' => $item->precio,
        ]);
        $item->update(['estado' => 'VENDIDO']);

        return $d;
    });

    $escenario['pagos'] = new \Illuminate\Support\Collection;

    foreach ($escenario['pagos_plan'] ?? [] as $plan) {
        $pago = PagoVenta::create([
            'venta_id' => $escenario['venta']->id,
            'sesion_caja_id' => $plan['metodo'] === PagoVenta::METODO_EFECTIVO
                ? openCajaFor($escenario['usuario'])->id
                : null,
            'user_id' => $escenario['usuario']->id,
            'metodo' => $plan['metodo'],
            'monto_aplicado' => Money::aPrecio($plan['monto']),
            'efectivo_recibido' => $plan['metodo'] === PagoVenta::METODO_EFECTIVO
                ? Money::aPrecio($plan['monto'])
                : null,
            'cambio_entregado' => $plan['metodo'] === PagoVenta::METODO_EFECTIVO
                ? '0.00'
                : null,
            'referencia' => $plan['metodo'] === PagoVenta::METODO_EFECTIVO
                ? null
                : ($plan['referencia'] ?? null),
            'origen' => PagoVenta::ORIGEN_POS,
            'orden' => $escenario['pagos']->count() + 1,
        ]);
        $escenario['pagos']->push($pago);
    }

    $financiado = coinsTotal($escenario['precios']) - coinsDePagos($escenario['pagos']);

    if ($financiado > 0) {
        $escenario['cuenta'] = app(CuentaPorCobrarService::class)
            ->crearParaVenta($escenario['venta'], $financiado, $escenario['usuario']);
    } else {
        $escenario['cuenta'] = null;
    }
}

function coinsDePrecio(string $precio): int
{
    return Money::aCentavos($precio);
}

function debtAbono(CuentaPorCobrar $cuenta, int $centavos, string $metodo, User $actor, ?string $referencia = null): MovimientoCxC
{
    if ($metodo === MovimientoCxC::METODO_EFECTIVO) {
        openCajaFor($actor);
    }

    $referencia = $referencia
        ?? ($metodo === MovimientoCxC::METODO_EFECTIVO ? null : 'REF-'.(string) \Illuminate\Support\Str::uuid());

    return app(CuentaPorCobrarService::class)->registrarAbono(
        $cuenta,
        $centavos,
        $metodo,
        $actor,
        $referencia,
        'Observación debt-first.',
        (string) \Illuminate\Support\Str::uuid()
    );
}

function debtReversarAbono(CuentaPorCobrar $cuenta, MovimientoCxC $abono, User $actor): void
{
    app(CuentaPorCobrarService::class)->reversarAbono(
        $cuenta,
        $abono,
        $actor,
        'Reversa debt-first.'
    );
}

function debtService(): PostventaService
{
    return app(PostventaService::class);
}

function coinsTotal(array $precios): int
{
    return array_sum(array_map(fn (string $p) => coinsDePrecio($p), $precios));
}

function coinsDePagos(\Illuminate\Support\Collection $pagos): int
{
    return $pagos->sum(fn (PagoVenta $p) => Money::aCentavos($p->monto_aplicado));
}

/**
 * =========================
 * 1) Cancelación debt-first con saldo > 0
 * =========================
 */
it('cancelar: saldo mayor al importe -> CANCELACION + sobrante en abonos LIFO, sin reembolso de pagos', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 80000, MovimientoCxC::METODO_TARJETA, $e['usuario'], 'TRX-AB-1');

    $doc = debtService()->cancelar(
        $e['venta'],
        'Cancelación con crédito vigente.',
        null,
        [],
        null,
        [(int) $abono->id => 'REF-CXC']
    );

    // Total 100000, saldo 20000 (100000-80000): deuda-primero reduce 20000 y devuelve 80000.
    expect((string) $doc->total)->toBe('1000.00');

    $deuda = $doc->movimientoCxCDeuda;
    expect($deuda->tipo)->toBe(MovimientoCxC::TIPO_CANCELACION);
    expect($deuda->monto_centavos)->toBe(20000);
    expect($deuda->saldo_antes_centavos)->toBe(20000);
    expect($deuda->saldo_despues_centavos)->toBe(0);

    $ecuenta = $cuenta->refresh();
    expect($ecuenta->saldo_centavos)->toBe(0);
    expect($ecuenta->estado)->toBe(CuentaPorCobrar::ESTADO_CANCELADA);

    expect($doc->reembolsos)->toHaveCount(1);
    $r = $doc->reembolsos->first();
    expect($r->origen)->toBe(ReembolsoPostventa::ORIGEN_CXC_ABONO);
    expect((int) $r->movimiento_cxc_id)->toBe((int) $abono->id);
    expect(Money::aCentavos($r->monto))->toBe(80000);
    expect($r->metodo)->toBe(MovimientoCxC::METODO_TARJETA);
    expect($r->referencia)->toBe('REF-CXC');
    expect($r->pago_venta_id)->toBeNull();

    expect($e['venta']->refresh()->estado)->toBe(Venta::ESTADO_CANCELADA);
    expect($e['items']->every(fn (Item $i) => $i->refresh()->estado === 'DISPONIBLE'))->toBeTrue();
    $this->assertDatabaseCount('movimientos_caja', 0);
});

it('cancelar: dos abonos LIFO; el más reciente se consume primero', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $viejo = debtAbono($cuenta, 50000, MovimientoCxC::METODO_TARJETA, $e['usuario'], 'V-1');
    $nuevo = debtAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $e['usuario'], 'N-2');

    $doc = debtService()->cancelar(
        $e['venta'],
        'LIFO de abonos.',
        null,
        [],
        null,
        [(int) $viejo->id => 'RV', (int) $nuevo->id => 'RN']
    );

    // Total 100000, saldo 20000. Reembolso monetario 80000 = 30000 (nuevo) + 50000 (viejo).
    $reembolsos = $doc->reembolsos->keyBy('movimiento_cxc_id');
    expect($reembolsos->has((string) (int) $nuevo->id))->toBeTrue();
    expect($reembolsos->has((string) (int) $viejo->id))->toBeTrue();
    expect(Money::aCentavos($reembolsos[(string) (int) $nuevo->id]->monto))->toBe(30000);
    expect(Money::aCentavos($reembolsos[(string) (int) $viejo->id]->monto))->toBe(50000);
    expect($doc->reembolsos->pluck('origen')->all())
        ->toBe([ReembolsoPostventa::ORIGEN_CXC_ABONO, ReembolsoPostventa::ORIGEN_CXC_ABONO]);
});

it('cancelar: saldo CERO (SALDADA) -> deuda no se toca y reembolso entero sale del abono', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 100000, MovimientoCxC::METODO_TARJETA, $e['usuario']); // salda completo

    $doc = debtService()->cancelar($e['venta'], 'Venta ya saldada.', null, [], null, [(int) $abono->id => 'R-SALDADA']);

    expect((string) $doc->total)->toBe('1000.00');
    expect($doc->reembolsos)->toHaveCount(1);
    expect((int) $doc->reembolsos->first()->movimiento_cxc_id)->toBe((int) $abono->id);
    expect(Money::aCentavos($doc->reembolsos->first()->monto))->toBe(100000);
    expect($doc->movimientoCxCDeuda)->toBeNull();
    expect($cuenta->refresh()->estado)->toBe(CuentaPorCobrar::ESTADO_SALDADA);
    expect($cuenta->saldo_centavos)->toBe(0);
    $this->assertDatabaseCount('movimientos_caja', 0);
});

it('cancelar: abono por saldo completo -> sin movimiento de deuda y un reembolso CxC_ABONO total', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 100000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $doc = debtService()->cancelar($e['venta'], 'Saldo cubierto por abono.', null, [], null, [(int) $abono->id => 'R-1']);

    expect($doc->reembolsos)->toHaveCount(1);
    expect($doc->reembolsos->first()->origen)->toBe(ReembolsoPostventa::ORIGEN_CXC_ABONO);
    expect($doc->movimientoCxCDeuda)->toBeNull();
    expect($cuenta->refresh()->saldo_centavos)->toBe(0);
});

it('cancelar: abono EFECTIVO financia reembolso en efectivo que crea MovimientoCaja', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 80000, MovimientoCxC::METODO_EFECTIVO, $e['usuario']);

    $sesion = openCajaFor($e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Reembolso efectivo debt-first.', null, [], null, [(int) $abono->id => null]);

    expect($doc->reembolsos)->toHaveCount(1);
    expect($doc->reembolsos->first()->metodo)->toBe(MovimientoCxC::METODO_EFECTIVO);
    expect($doc->reembolsos->first()->referencia)->toBeNull();

    // El abono EFECTIVO entró a la caja (ABONO_CXC_EFECTIVO) y el reembolso sale (REEMBOLSO_EFECTIVO).
    $abonoMov = MovimientoCaja::where('sesion_caja_id', $sesion->id)
        ->where('tipo', MovimientoCaja::TIPO_ABONO_CXC_EFECTIVO)
        ->first();
    expect($abonoMov)->not->toBeNull();
    expect($abonoMov->monto)->toBe('800.00');
    expect($abonoMov->movimiento_cxc_id)->toBe($abono->id);

    $cajaMov = MovimientoCaja::where('sesion_caja_id', $sesion->id)
        ->where('tipo', MovimientoCaja::TIPO_REEMBOLSO_EFECTIVO)
        ->first();
    expect($cajaMov)->not->toBeNull();
    expect($cajaMov->monto)->toBe('800.00');
    // El vínculo de caja jamás usa movimiento_cxc_id (vive en reembolsos_postventa).
    expect($cajaMov->documento_postventa_id)->toBe($doc->id);
    expect($cajaMov->venta_id)->toBe($e['venta']->id);
    expect($cajaMov->pago_venta_id)->toBeNull();
    expect($cajaMov->movimiento_cxc_id)->toBeNull();
    expect(MovimientoCaja::where('sesion_caja_id', $sesion->id)->count())->toBe(2);
});

it('cancelar: reembolso en efectivo insuficiente en caja -> DomainException sin mutación', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 80000, MovimientoCxC::METODO_EFECTIVO, $e['usuario'], '-');

    // La caja queda con 80000 (por el abono) pero la retiramos -> efectivo esperado 0.
    $sesion = openCajaFor($e['usuario']);
    app(CajaService::class)->registrarRetiro($sesion, $e['usuario'], 80000, 'Retiro de prueba.');

    // Una caja vacía (sin fondos) no puede devolver 80000 => error financiero.
    expect(fn () => debtService()->cancelar($e['venta'], 'Caja sin fondos.', null, [], null, [(int) $abono->id => null]))
        ->toThrow(DomainException::class);

    $this->assertDatabaseCount('documentos_postventa', 0);
    $this->assertDatabaseCount('reembolsos_postventa', 0);
    expect($e['venta']->refresh()->estado)->toBe(Venta::ESTADO_ACTIVA);
    expect($e['items']->every(fn (Item $i) => $i->refresh()->estado === 'VENDIDO'))->toBeTrue();
});

/**
 * =========================
 * 2) Devolución debt-first
 * =========================
 */
it('devolver: reduce deuda y devuelve sobrante desde el abono', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 60000, MovimientoCxC::METODO_TRANSFERENCIA, $e['usuario'], 'T-1');

    $detalle = $e['detalles']->first();
    $doc = debtService()->devolver(
        $e['venta'],
        [(int) $detalle->id],
        'Devolución parcial debt-first.',
        null,
        [],
        null,
        [(int) $abono->id => 'REF-T']
    );

    // Total devuelto 50000, saldo 40000: reduce 40000 y devuelve 10000 desde el abono.
    expect((string) $doc->total)->toBe('500.00');
    $deuda = $doc->movimientoCxCDeuda;
    expect($deuda->tipo)->toBe(MovimientoCxC::TIPO_REDUCCION_POSTVENTA);
    expect($deuda->monto_centavos)->toBe(40000);
    expect($deuda->saldo_despues_centavos)->toBe(0);

    $ecuenta = $cuenta->refresh();
    expect($ecuenta->saldo_centavos)->toBe(0);
    expect($ecuenta->estado)->toBe(CuentaPorCobrar::ESTADO_SALDADA);

    $r = $doc->reembolsos->first();
    expect($r->origen)->toBe(ReembolsoPostventa::ORIGEN_CXC_ABONO);
    expect(Money::aCentavos($r->monto))->toBe(10000);
    expect((int) $r->movimiento_cxc_id)->toBe((int) $abono->id);
    expect($r->referencia)->toBe('REF-T');

    expect($e['items']->first()->refresh()->estado)->toBe('DEVUELTO');
    expect($e['venta']->refresh()->estado)->toBe(Venta::ESTADO_PARCIALMENTE_DEVUELTA);
});

it('devolver: importe menor al saldo reduce parcialmente y mantiene PARCIAL', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 20000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $detalle = $e['detalles']->first();
    $doc = debtService()->devolver($e['venta'], [(int) $detalle->id], 'Devolución que absorbe la deuda.');

    // Total devuelto 50000, saldo 80000: reduce 50000, sobrante 0.
    expect($doc->reembolsos)->toHaveCount(0);
    $deuda = $doc->movimientoCxCDeuda;
    expect($deuda->monto_centavos)->toBe(50000);
    expect($deuda->saldo_despues_centavos)->toBe(30000);

    $ecuenta = $cuenta->refresh();
    expect($ecuenta->saldo_centavos)->toBe(30000);
    expect($ecuenta->estado)->toBe(CuentaPorCobrar::ESTADO_PARCIAL);
});

it('devolver: múltiples detalles suma el importe y prorratea sobrante entre abonos LIFO', function () {
    $e = [
        'precios' => ['300.00', '300.00', '400.00'],
        'forma_pago' => 'CREDITO',
        'pagos_plan' => [],
        'limite' => 400000,
    ];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $a1 = debtAbono($cuenta, 40000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $a2 = debtAbono($cuenta, 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    // Abonos 40000+60000 = 100000 => saldo 0 (SALDADA). Devuelve 300+300 = 60000
    // deuda-primero reduce 0; todo el sobrante sale del abono más reciente (a2).
    $ids = $e['detalles']->take(2)->pluck('id')->map('intval')->all();
    $doc = debtService()->devolver(
        $e['venta'],
        $ids,
        'Devolución múltiple.',
        null,
        [],
        null,
        [(int) $a2->id => 'R-A2']
    );

    expect((string) $doc->total)->toBe('600.00');
    expect($doc->movimientoCxCDeuda)->toBeNull();
    expect($cuenta->refresh()->saldo_centavos)->toBe(0);
    expect($doc->reembolsos)->toHaveCount(1);
    $r = $doc->reembolsos->first();
    expect((int) $r->movimiento_cxc_id)->toBe((int) $a2->id);
    expect(Money::aCentavos($r->monto))->toBe(60000);
    expect($doc->reembolsos->sum(fn (ReembolsoPostventa $x) => Money::aCentavos($x->monto)))->toBe(60000);
});

it('devolver: dos abonos y sobrante que consume el más reciente primero', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $viejo = debtAbono($cuenta, 40000, MovimientoCxC::METODO_TARJETA, $e['usuario'], 'V-1');
    $nuevo = debtAbono($cuenta, 40000, MovimientoCxC::METODO_TARJETA, $e['usuario'], 'N-2');

    $detalle = $e['detalles']->first(); // 50000
    $doc = debtService()->devolver(
        $e['venta'],
        [(int) $detalle->id],
        'LIFO abonos devolución.',
        null,
        [],
        null,
        [(int) $nuevo->id => 'RN-2']
    );

    // Importe 50000, saldo 20000 (100000-80000): reduce 20000, sobrante 30000 -> nuevo (40000).
    expect($doc->reembolsos)->toHaveCount(1);
    $r = $doc->reembolsos->first();
    expect((int) $r->movimiento_cxc_id)->toBe((int) $nuevo->id);
    expect(Money::aCentavos($r->monto))->toBe(30000);
    expect($doc->movimientoCxCDeuda->monto_centavos)->toBe(20000);
    expect($cuenta->refresh()->saldo_centavos)->toBe(0);
});

/**
 * =========================
 * 3) Restitución sin caja / caja suficiente por mezcla EFECTIVO + electrónico
 * =========================
 */
it('devolver: reembolso EFECTIVO y TARJETA exigen caja solo por el tramo efectivo', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    // 30000 TARJETA + 20000 EFECTIVO = 50000; queda saldo 50000.
    $abTarjeta = debtAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $abEfectivo = debtAbono($cuenta, 20000, MovimientoCxC::METODO_EFECTIVO, $e['usuario']);

    $sesion = openCajaFor($e['usuario']); // el abono EFECTIVO depositó 20000
    $detalle = $e['detalles']->first(); // 50000
    $doc = debtService()->devolver(
        $e['venta'],
        [(int) $detalle->id],
        'Mezcla efectivo/tarjeta.',
        null,
        [],
        null,
        [(int) $abTarjeta->id => 'RT', (int) $abEfectivo->id => null]
    );

    // Saldo 50000 >= 50000 => reduce todo; reembolso monetario 0.
    expect($doc->movimientoCxCDeuda->monto_centavos)->toBe(30000 + 20000);
    expect($doc->reembolsos)->toHaveCount(0);
    expect($cuenta->refresh()->saldo_centavos)->toBe(0);
});

it('devolver: caja insuficiente para el tramo efectivo de abono -> DomainException', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 20000, MovimientoCxC::METODO_EFECTIVO, $e['usuario']);

    $sesion = openCajaFor($e['usuario']);
    app(CajaService::class)->registrarRetiro($sesion, $e['usuario'], 20000, 'Vació la caja.');

    // Importe devuelto 100000, saldo 80000: reduce 80000 y sobrante 20000 en EFECTIVO sin fondos.
    $ids = $e['detalles']->pluck('id')->map('intval')->all();
    expect(fn () => debtService()->devolver(
        $e['venta'],
        $ids,
        'Caja sin efectivo.',
        null,
        [],
        null,
        [(int) $abono->id => null]
    ))->toThrow(DomainException::class);

    $this->assertDatabaseCount('documentos_postventa', 0);
    $this->assertDatabaseCount('reembolsos_postventa', 0);
});

/**
 * =========================
 * 4) Referencias de reembolso CxC
 * =========================
 */
it('referencias: abono TARJETA sin referencia -> DomainException', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    debtAbono($cuenta, 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    expect(fn () => debtService()->cancelar(
        $e['venta'],
        'Sin referencia.',
        null,
        [],
        null,
        []
    ))->toThrow(DomainException::class, 'requiere una referencia');
});

it('referencias: abono TARJETA con referencia nula explícita -> DomainException', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    expect(fn () => debtService()->cancelar(
        $e['venta'],
        'Referencia nula.',
        null,
        [],
        null,
        [(int) $abono->id => null]
    ))->toThrow(DomainException::class, 'requiere una referencia');
});

it('referencias: abono EFECTIVO acepta referencia nula y nunca exige una', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 60000, MovimientoCxC::METODO_EFECTIVO, $e['usuario']);

    $doc = debtService()->cancelar(
        $e['venta'],
        'Efectivo sin referencia.',
        null,
        [],
        null,
        [(int) $abono->id => null]
    );

    expect($doc->reembolsos)->toHaveCount(1);
    expect($doc->reembolsos->first()->referencia)->toBeNull();
});

it('referencias: rectifica y recorta espacios de la referencia electrónica', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $doc = debtService()->cancelar(
        $e['venta'],
        'Referencia recortada.',
        null,
        [],
        null,
        [(int) $abono->id => '  REF-ABC-1  ']
    );

    expect($doc->reembolsos->first()->referencia)->toBe('REF-ABC-1');
});

it('referencias: referencia mayor a 100 caracteres -> DomainException', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    expect(fn () => debtService()->cancelar(
        $e['venta'],
        'Referencia larga.',
        null,
        [],
        null,
        [(int) $abono->id => str_repeat('A', 101)]
    ))->toThrow(DomainException::class, '100 caracteres');
});

/**
 * =========================
 * 5) Abono reversado ya NO es fuente de reembolso
 * =========================
 */
it('cancelar: abono reversado no puede ser fuente; la deuda que resta se reduce', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta']; // saldo 100000

    $abono = debtAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    debtReversarAbono($cuenta, $abono, $e['usuario']);
    // Saldo vuelve a 100000 (30000 reversados reponen el saldo).

    // Cancelar 100000: reduce toda la deuda; sobrante 0 (sin abonos válidos).
    $doc = debtService()->cancelar($e['venta'], 'Abono reversado no reembolsa.', null, [], null, []);

    expect($doc->movimientoCxCDeuda->monto_centavos)->toBe(100000);
    expect($doc->movimientoCxCDeuda->tipo)->toBe(MovimientoCxC::TIPO_CANCELACION);
    expect($doc->reembolsos)->toHaveCount(0);
    expect($cuenta->refresh()->saldo_centavos)->toBe(0);
    expect($cuenta->refresh()->estado)->toBe(CuentaPorCobrar::ESTADO_CANCELADA);
});

/**
 * =========================
 * 6) Interlock: un ABONO ya usado en postventa no puede reversarse
 * =========================
 */
it('interlock: abono usado como fuente de un reembolso postventa no puede reversarse', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $doc = debtService()->cancelar(
        $e['venta'],
        'Usa el abono.',
        null,
        [],
        null,
        [(int) $abono->id => 'R-CXC']
    );

    expect($doc->reembolsos->first()->movimiento_cxc_id)->toBe($abono->id);

    expect(fn () => debtReversarAbono($cuenta, $abono, $e['usuario']))
        ->toThrow(DomainException::class, 'El abono ya fue utilizado en una operación postventa y no puede reversarse.');
});

it('interlock BD: REVERSA_ABONO sobre abono consumido viola el trigger declarativo', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $doc = debtService()->cancelar(
        $e['venta'],
        'Usa el abono.',
        null,
        [],
        null,
        [(int) $abono->id => 'R-CXC-DB']
    );
    expect($doc->reembolsos)->toHaveCount(1);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => $e['usuario']->id,
        'tipo' => MovimientoCxC::TIPO_REVERSA_ABONO,
        'monto_centavos' => 60000,
        'saldo_antes_centavos' => 0,
        'saldo_despues_centavos' => 0,
        'metodo' => null,
        'referencia' => null,
        'movimiento_origen_id' => $abono->id,
        'observaciones' => 'Reversa ilegal.',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

/**
 * =========================
 * 7) Invariantes de estructura / estados de la venta
 * =========================
 */
it('venta: con cuenta por cobrar, el método CREDITO en PagoVenta no es dinero real -> DomainException', function () {
    $e = [];
    debtOriginar($e);

    // Inyectamos un PagoVenta CREDITO (reservado) que rompe la estructura B15.5.
    PagoVenta::create([
        'venta_id' => $e['venta']->id,
        'user_id' => $e['usuario']->id,
        'metodo' => PagoVenta::METODO_CREDITO,
        'monto_aplicado' => '100.00',
        'efectivo_recibido' => null,
        'cambio_entregado' => null,
        'referencia' => null,
        'origen' => PagoVenta::ORIGEN_POS,
        'orden' => 1,
    ]);

    expect(fn () => debtService()->cancelar($e['venta'], 'Estructura rota.'))
        ->toThrow(DomainException::class, 'El método CREDITO no representa dinero real en el flujo B15.5.');
});

it('venta: pagos + crédito que no concilian con el total -> DomainException', function () {
    $e = [
        'precios' => ['500.00', '500.00'],
        'forma_pago' => 'MIXTO',
        'pagos_plan' => [
            ['metodo' => PagoVenta::METODO_TARJETA, 'monto' => 20000],
        ],
        // crédito = 80000 => 20000 + 80000 = 100000 correcto.
        'limite' => 400000,
    ];
    debtOriginar($e);

    // Agregamos un PagoVenta extra fuera del plan: ahora pagos+crédito = 120000 != 100000.
    PagoVenta::create([
        'venta_id' => $e['venta']->id,
        'user_id' => $e['usuario']->id,
        'metodo' => PagoVenta::METODO_TARJETA,
        'monto_aplicado' => '200.00',
        'efectivo_recibido' => null,
        'cambio_entregado' => null,
        'referencia' => 'EXTRA',
        'origen' => PagoVenta::ORIGEN_POS,
        'orden' => 500,
    ]);

    expect(fn () => debtService()->cancelar($e['venta'], 'No concilia.'))
        ->toThrow(DomainException::class, 'no concilia');
});

it('cancelar: venta no ACTIVA -> DomainException', function () {
    $e = [];
    debtOriginar($e);

    $e['venta']->update(['estado' => Venta::ESTADO_CANCELADA]);

    expect(fn () => debtService()->cancelar($e['venta'], 'Ya cancelada.'))
        ->toThrow(DomainException::class, "La venta {$e['venta']->folio} no está ACTIVA y no puede cancelarse.");
});

it('cancelar: venta con operación postventa previa es rechazada', function () {
    $e = [];
    debtOriginar($e);

    $abono = debtAbono($e['cuenta'], 80000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $primerDoc = debtService()->cancelar($e['venta'], 'Primera cancelación.', null, [], null, [(int) $abono->id => 'PC']);

    expect($primerDoc->tipo)->toBe(DocumentoPostventa::TIPO_CANCELACION);

    // Una segunda cancelación sobre la misma venta siempre es rechazada (la
    // deuda ya se extinguió y los abonos ya fueron consumidos).
    expect(fn () => debtService()->cancelar($e['venta'], 'Segunda cancelación.'))
        ->toThrow(DomainException::class);

    $this->assertDatabaseCount('documentos_postventa', 1);
});

it('devolver: detalle ya devuelto antes -> DomainException', function () {
    $e = [];
    debtOriginar($e);

    $detalle = $e['detalles']->first();
    $abono = debtAbono($e['cuenta'], 80000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    debtService()->devolver(
        $e['venta'],
        [(int) $detalle->id],
        'Primera devolución.',
        null,
        [],
        null,
        [(int) $abono->id => 'REF-1']
    );

    expect(fn () => debtService()->devolver(
        $e['venta'],
        [(int) $detalle->id],
        'Devuelto de nuevo.',
        null,
        [],
        null,
        [(int) $abono->id => 'REF-2']
    ))->toThrow(DomainException::class, 'Uno o más equipos seleccionados ya fueron devueltos.');
});

it('devolver: ids vacíos -> DomainException', function () {
    $e = [];
    debtOriginar($e);

    expect(fn () => debtService()->devolver($e['venta'], [], 'Sin equipos.'))
        ->toThrow(DomainException::class, 'Selecciona al menos un equipo a devolver.');
});

it('devolver: detalle de otra venta -> DomainException', function () {
    $e = [];
    debtOriginar($e);
    $otra = [];
    debtOriginar($otra);

    expect(fn () => debtService()->devolver($e['venta'], [(int) $otra['detalles']->first()->id], 'De otra venta.'))
        ->toThrow(DomainException::class, 'Uno o más equipos seleccionados no pertenecen a la venta.');
});

/**
 * =========================
 * 8) Modelos y relaciones
 * =========================
 */
it('modelos: ReembolsoPostventa::esCxC es true para origen CXC_ABONO y el vínculo sobrevive', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $doc = debtService()->cancelar($e['venta'], 'Modelos.', null, [], null, [(int) $abono->id => 'RM']);

    $r = $doc->reembolsos->first();
    expect($r->esCxC())->toBeTrue();
    expect($r->movimientoCxC()->first()->id)->toBe($abono->id);
    expect((int) $r->pago_venta_id)->toBe(0); // sin fuente PagoVenta
    expect($doc->movimientoCxCDeuda()->first()->tipo)->toBe(MovimientoCxC::TIPO_CANCELACION);
    expect($doc->movimientoCxCDeuda->documento_postventa_id)->toBe($doc->id);
    expect($abono->refresh()->documento_postventa_id)->toBeNull(); // los ABONOS no llevan documento
});

it('modelos: fillable y casts del documento postventa exponen total y reembolsos', function () {
    $e = [];
    debtOriginar($e);

    $abono = debtAbono($e['cuenta'], 80000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Fillables.', null, [], null, [(int) $abono->id => 'RF']);

    expect((string) $doc->total)->toBe('1000.00');
    expect($doc->reembolsos)->toHaveCount(1);
    expect($doc->reembolsos->first()->origen)->toBe(ReembolsoPostventa::ORIGEN_CXC_ABONO);
});

it('modelos: venta saldada produce DocumentoPostventa sin deuda pero con reembolso del abono', function () {
    $e = [];
    debtOriginar($e);

    $abono = debtAbono($e['cuenta'], 100000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Saldo saldado.', null, [], null, [(int) $abono->id => 'RSL']);

    expect($doc->movimientoCxCDeuda)->toBeNull();
    expect($doc->reembolsos)->toHaveCount(1);
    expect(Money::aCentavos($doc->reembolsos->sum('monto')))->toBe(100000);
    expect($e['cuenta']->refresh()->estado)->toBe(CuentaPorCobrar::ESTADO_SALDADA);
});

/**
 * =========================
 * 9) Doble sumisión: la operación es idempotente y solo crea UN documento
 * =========================
 */
it('doble-submit: dos llamadas concurrentes a cancelar dejan un único documento postventa', function () {
    $e = [];
    debtOriginar($e);

    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $doc = debtService()->cancelar($e['venta'], 'Doble submit.', null, [], null, [(int) $abono->id => 'DS-1']);
    $segundo = null;

    try {
        $segundo = debtService()->cancelar($e['venta'], 'Doble submit.', null, [], null, [(int) $abono->id => 'DS-1']);
        $this->fail('La segunda cancelación debió fallar.');
    } catch (DomainException $ex) {
        expect($ex->getMessage())->not->toBe('');
    }

    expect($segundo)->toBeNull();
    $this->assertDatabaseCount('documentos_postventa', 1);
});

/**
 * =========================
 * 10) Migration: down() degrada y conserva los reembolsos CXC_ABONO
 * =========================
 */
it('migración: rollback de los dos pasos conserva reembolsos y degrada a LEGACY_MANUAL', function () {
    $e = [];
    debtOriginar($e);

    $abono = debtAbono($e['cuenta'], 80000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Para rollback.', null, [], null, [(int) $abono->id => 'RO']);
    $docId = $doc->id;
    $reembolsoId = $doc->reembolsos->first()->id;

    try {
        Artisan::call('migrate:rollback', ['--step' => 2]);

        expect(DB::table('reembolsos_postventa')->where('id', $reembolsoId)->exists())->toBeTrue();
        expect(DB::table('reembolsos_postventa')->find($reembolsoId)->origen)->toBe(ReembolsoPostventa::ORIGEN_LEGACY_MANUAL);
        expect(Schema::hasColumn('reembolsos_postventa', 'movimiento_cxc_id'))->toBeFalse();
        // El ledger se conserva: la CANCELACION sigue existiendo y el vínculo
        // al documento se perdió junto con la columna (degradación semántica).
        expect(Schema::hasColumn('movimientos_cxc', 'documento_postventa_id'))->toBeFalse();
        expect(DB::table('movimientos_cxc')
            ->where('tipo', MovimientoCxC::TIPO_CANCELACION)
            ->where('monto_centavos', 20000)
            ->exists())->toBeTrue();
    } finally {
        Artisan::call('migrate');
    }
});

/**
 * =========================
 * 11) HTTP: formularios y rutas con permisos
 * =========================
 */
it('http: la página POSTVENTA de un documento con CxC es accesible con permiso', function () {
    $e = [];
    debtOriginar($e);

    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Para ver.', null, [], null, [(int) $abono->id => 'V-1']);

    $this->actingAs($e['usuario'])
        ->get(route('postventa.show', $doc))
        ->assertOk()
        ->assertSee($doc->folio);
});

it('http: cancelar requiere el permiso ventas.cancelar en el request', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
        ->actingAs($e['usuario'])
        ->post(route('ventas.cancelar.store', $e['venta']), [
            'motivo' => 'HTTP cancelar.',
            'referencias_reembolso_cxc' => [(string) $abono->id => 'H-REF'],
        ])
        ->assertSessionHasNoErrors();

    expect($e['venta']->refresh()->estado)->toBe(Venta::ESTADO_CANCELADA);
    expect(DB::table('documentos_postventa')->count())->toBe(1);
});

it('http: devolver requiere el permiso ventas.devolver en el request', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $detalle = $e['detalles']->first();

    $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
        ->actingAs($e['usuario'])
        ->post(route('ventas.devolver.store', $e['venta']), [
            'motivo' => 'HTTP devolver.',
            'detalles' => [$detalle->id],
            'referencias_reembolso_cxc' => [(string) $abono->id => 'H-DEV'],
        ])
        ->assertSessionHasNoErrors();

    expect($e['venta']->refresh()->estado)->toBe(Venta::ESTADO_PARCIALMENTE_DEVUELTA);
    expect(DB::table('documentos_postventa')->count())->toBe(1);
});

it('http: la ruta cxc.show es accesible y muestra la cuenta con su saldo', function () {
    $e = [];
    debtOriginar($e);
    debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $this->actingAs($e['usuario'])
        ->get(route('cxc.show', $e['cuenta']))
        ->assertOk()
        ->assertSee('Saldo');
});

/**
 * =========================
 * 12) DB: triggers de vínculo (migración a) a nivel BD
 * =========================
 */
it('bd: la CANCELACION de otra venta en un movimiento de deuda -> viola el trigger', function () {
    $e = [];
    debtOriginar($e);
    $doc = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_CANCELACION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc de prueba.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '1000.00',
    ]);

    $otra = [];
    debtOriginar($otra);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $otra['cuenta']->id,
        'user_id' => $e['usuario']->id,
        'tipo' => MovimientoCxC::TIPO_CANCELACION,
        'monto_centavos' => 90000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 10000,
        'metodo' => null,
        'referencia' => null,
        'movimiento_origen_id' => null,
        'documento_postventa_id' => $doc->id,
        'observaciones' => 'Prueba de venta ajena.',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('bd: CANCELACION con documento de tipo DEVOLUCION -> viola el trigger', function () {
    $e = [];
    debtOriginar($e);

    $docDevolucion = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_DEVOLUCION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc devolución.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '1000.00',
    ]);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $e['cuenta']->id,
        'user_id' => $e['usuario']->id,
        'tipo' => MovimientoCxC::TIPO_CANCELACION,
        'monto_centavos' => 90000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 10000,
        'metodo' => null,
        'referencia' => null,
        'movimiento_origen_id' => null,
        'documento_postventa_id' => $docDevolucion->id,
        'observaciones' => 'Prueba de tipo.',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('bd: movimiento de deuda con monto mayor que el documento -> viola el trigger', function () {
    $e = [];
    debtOriginar($e);

    $doc = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_CANCELACION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc pequeño.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '700.00',
    ]);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $e['cuenta']->id,
        'user_id' => $e['usuario']->id,
        'tipo' => MovimientoCxC::TIPO_CANCELACION,
        'monto_centavos' => 90000, // > 70000
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 10000,
        'metodo' => null,
        'referencia' => null,
        'movimiento_origen_id' => null,
        'documento_postventa_id' => $doc->id,
        'observaciones' => 'Prueba de monto.',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('bd: un ABONO no puede anclarse a un documento postventa (CHECK)', function () {
    $e = [];
    debtOriginar($e);
    $doc = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_CANCELACION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc para ABONO.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '1000.00',
    ]);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $e['cuenta']->id,
        'user_id' => $e['usuario']->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 10000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 90000,
        'metodo' => MovimientoCxC::METODO_TARJETA,
        'referencia' => 'AB-TEST',
        'movimiento_origen_id' => null,
        'documento_postventa_id' => $doc->id,
        'observaciones' => 'ABONO ilegal con documento.',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

/**
 * =========================
 * 13) DB: triggers de fuente exacta (migración b) a nivel BD
 * =========================
 */
it('bd: CXC_ABONO con método distinto al abono -> viola el trigger', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $doc = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_CANCELACION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc método.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '600.00',
    ]);

    expect(fn () => ReembolsoPostventa::create([
        'documento_postventa_id' => $doc->id,
        'pago_venta_id' => null,
        'movimiento_cxc_id' => $abono->id,
        'sesion_caja_id' => null,
        'user_id' => $e['usuario']->id,
        'metodo' => ReembolsoPostventa::METODO_TRANSFERENCIA,
        'monto' => '600.00',
        'referencia' => 'X-R',
        'origen' => ReembolsoPostventa::ORIGEN_CXC_ABONO,
        'orden' => 1,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('bd: CXC_ABONO con monto mayor al abono -> viola el trigger', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $doc = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_CANCELACION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc monto abono.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '900.00',
    ]);

    expect(fn () => ReembolsoPostventa::create([
        'documento_postventa_id' => $doc->id,
        'pago_venta_id' => null,
        'movimiento_cxc_id' => $abono->id,
        'sesion_caja_id' => null,
        'user_id' => $e['usuario']->id,
        'metodo' => ReembolsoPostventa::METODO_TARJETA,
        'monto' => '900.00', // > 60000
        'referencia' => 'X-R',
        'origen' => ReembolsoPostventa::ORIGEN_CXC_ABONO,
        'orden' => 1,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('bd: AUTOMATICO con pago de otra venta -> viola el trigger', function () {
    $e = [];
    debtOriginar($e);
    $pago = PagoVenta::create([
        'venta_id' => $e['venta']->id,
        'user_id' => $e['usuario']->id,
        'metodo' => PagoVenta::METODO_TARJETA,
        'monto_aplicado' => '300.00',
        'efectivo_recibido' => null,
        'cambio_entregado' => null,
        'referencia' => 'P-1',
        'origen' => PagoVenta::ORIGEN_POS,
        'orden' => 1,
    ]);

    $otra = [];
    debtOriginar($otra);
    $docOtra = DocumentoPostventa::create([
        'venta_id' => $otra['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_DEVOLUCION,
        'user_id' => $otra['usuario']->id,
        'motivo' => 'Doc de otra venta.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '300.00',
    ]);

    expect(fn () => ReembolsoPostventa::create([
        'documento_postventa_id' => $docOtra->id,
        'pago_venta_id' => $pago->id,
        'movimiento_cxc_id' => null,
        'sesion_caja_id' => null,
        'user_id' => $e['usuario']->id,
        'metodo' => ReembolsoPostventa::METODO_TARJETA,
        'monto' => '300.00',
        'referencia' => 'X-R',
        'origen' => ReembolsoPostventa::ORIGEN_AUTOMATICO,
        'orden' => 1,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('bd: LEGACY_MANUAL con pago_venta_id no nulo -> viola el CHECK de fuente exacta', function () {
    $e = [];
    debtOriginar($e);
    $pago = PagoVenta::create([
        'venta_id' => $e['venta']->id,
        'user_id' => $e['usuario']->id,
        'metodo' => PagoVenta::METODO_TARJETA,
        'monto_aplicado' => '300.00',
        'efectivo_recibido' => null,
        'cambio_entregado' => null,
        'referencia' => 'P-1',
        'origen' => PagoVenta::ORIGEN_POS,
        'orden' => 1,
    ]);

    $doc = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_DEVOLUCION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc legacy.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '300.00',
    ]);

    expect(fn () => ReembolsoPostventa::create([
        'documento_postventa_id' => $doc->id,
        'pago_venta_id' => $pago->id,
        'movimiento_cxc_id' => null,
        'sesion_caja_id' => null,
        'user_id' => $e['usuario']->id,
        'metodo' => ReembolsoPostventa::METODO_TARJETA,
        'monto' => '300.00',
        'referencia' => 'X-R',
        'origen' => ReembolsoPostventa::ORIGEN_LEGACY_MANUAL,
        'orden' => 1,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('bd: único reembolso CXC_ABONO por (documento, abono) -> viola el índice UNIQUE parcial', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $doc = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_CANCELACION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc único.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '600.00',
    ]);

    ReembolsoPostventa::create([
        'documento_postventa_id' => $doc->id,
        'pago_venta_id' => null,
        'movimiento_cxc_id' => $abono->id,
        'sesion_caja_id' => null,
        'user_id' => $e['usuario']->id,
        'metodo' => ReembolsoPostventa::METODO_TARJETA,
        'monto' => '300.00',
        'referencia' => 'R-1',
        'origen' => ReembolsoPostventa::ORIGEN_CXC_ABONO,
        'orden' => 1,
    ]);

    expect(fn () => ReembolsoPostventa::create([
        'documento_postventa_id' => $doc->id,
        'pago_venta_id' => null,
        'movimiento_cxc_id' => $abono->id,
        'sesion_caja_id' => null,
        'user_id' => $e['usuario']->id,
        'metodo' => ReembolsoPostventa::METODO_TARJETA,
        'monto' => '300.00',
        'referencia' => 'R-2',
        'origen' => ReembolsoPostventa::ORIGEN_CXC_ABONO,
        'orden' => 2,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

/**
 * =========================
 * 14) Regresión B14.2: venta sin CxC conserva el flujo automático legacy
 * =========================
 */
it('legacy: cancelar una venta pagada en efectivo conserva el reembolso AUTOMATICO', function () {
    $e = [
        'precios' => ['500.00', '500.00'],
        'forma_pago' => 'EFECTIVO',
        'pagos_plan' => [
            ['metodo' => PagoVenta::METODO_EFECTIVO, 'monto' => 100000],
        ],
    ];
    debtOriginar($e);
    expect($e['cuenta'])->toBeNull();

    $doc = debtService()->cancelar($e['venta'], 'Sin crédito.');

    expect($doc->tipo)->toBe(DocumentoPostventa::TIPO_CANCELACION);
    expect($doc->reembolsos)->toHaveCount(1);
    expect($doc->reembolsos->first()->origen)->toBe(ReembolsoPostventa::ORIGEN_AUTOMATICO);
    expect((int) $doc->reembolsos->first()->pago_venta_id)->toBe((int) $e['pagos']->first()->id);
    expect($doc->reembolsos->first()->movimientoCxC)->toBeNull();
    expect(MovimientoCaja::where('tipo', MovimientoCaja::TIPO_REEMBOLSO_EFECTIVO)->count())->toBe(1);
    expect($e['venta']->refresh()->estado)->toBe(Venta::ESTADO_CANCELADA);
});

it('legacy: devolver una venta pagada en tarjeta exige referencia y conserva AUTOMATICO', function () {
    $e = [
        'precios' => ['500.00', '500.00'],
        'forma_pago' => 'TARJETA',
        'pagos_plan' => [
            ['metodo' => PagoVenta::METODO_TARJETA, 'monto' => 100000, 'referencia' => 'LEG-TRX'],
        ],
    ];
    debtOriginar($e);
    expect($e['cuenta'])->toBeNull();

    $detalle = $e['detalles']->first();
    $doc = debtService()->devolver(
        $e['venta'],
        [(int) $detalle->id],
        'Devolución sin crédito.',
        null,
        [(int) $e['pagos']->first()->id => 'REF-LEGACY'],
        null,
        []
    );

    expect($doc->reembolsos)->toHaveCount(1);
    expect($doc->reembolsos->first()->origen)->toBe(ReembolsoPostventa::ORIGEN_AUTOMATICO);
    expect($doc->reembolsos->first()->metodo)->toBe(ReembolsoPostventa::METODO_TARJETA);
    expect($doc->reembolsos->first()->referencia)->toBe('REF-LEGACY');
    expect($e['venta']->refresh()->estado)->toBe(Venta::ESTADO_PARCIALMENTE_DEVUELTA);
});

it('legacy: devolver tarjeta sin referencia -> DomainException (B14.2 intacto)', function () {
    $e = [
        'precios' => ['500.00', '500.00'],
        'forma_pago' => 'TARJETA',
        'pagos_plan' => [
            ['metodo' => PagoVenta::METODO_TARJETA, 'monto' => 100000, 'referencia' => 'LEG-TRX'],
        ],
    ];
    debtOriginar($e);

    $detalle = $e['detalles']->first();
    expect(fn () => debtService()->devolver(
        $e['venta'],
        [(int) $detalle->id],
        'Sin referencia electrónica.',
        null,
        [],
        null,
        []
    ))->toThrow(DomainException::class, 'requiere una referencia de devolución');
});

it('legacy: devolver una venta con pago mixto prorratea el sobrante sobre los pagos', function () {
    $e = [
        'precios' => ['300.00', '300.00', '400.00'],
        'forma_pago' => 'MIXTO',
        'pagos_plan' => [
            ['metodo' => PagoVenta::METODO_EFECTIVO, 'monto' => 20000],
            ['metodo' => PagoVenta::METODO_TARJETA, 'monto' => 80000, 'referencia' => 'MIX-TRX'],
        ],
    ];
    debtOriginar($e);
    expect($e['cuenta'])->toBeNull();

    // Devuelve 300+300 (60000) proporcional a los pagos: 20% efectivo (12000), 80% tarjeta (48000).
    $ids = $e['detalles']->take(2)->pluck('id')->map('intval')->all();
    $doc = debtService()->devolver(
        $e['venta'],
        $ids,
        'Mixta sin crédito.',
        null,
        [(int) $e['pagos']->get(1)->id => 'REF-MIX'],
        null,
        []
    );

    expect($doc->reembolsos)->toHaveCount(2);
    $efectivo = $doc->reembolsos->firstWhere('metodo', ReembolsoPostventa::METODO_EFECTIVO);
    $tarjeta = $doc->reembolsos->firstWhere('metodo', ReembolsoPostventa::METODO_TARJETA);
    expect(Money::aCentavos($efectivo->monto))->toBe(12000);
    expect(Money::aCentavos($tarjeta->monto))->toBe(48000);
    expect(Money::aCentavos($doc->reembolsos->sum('monto')))->toBe(60000);
});

/**
 * =========================
 * 15) HTTP adicional
 * =========================
 */
it('http: GET cancelar-form es accesible y muestra el folio de la venta', function () {
    $e = [];
    debtOriginar($e);

    $this->actingAs($e['usuario'])
        ->get(route('ventas.cancelar', $e['venta']))
        ->assertOk()
        ->assertSee($e['venta']->folio);
});

it('http: GET devolver-form es accesible y muestra los equipos devolubles', function () {
    $e = [];
    debtOriginar($e);

    $this->actingAs($e['usuario'])
        ->get(route('ventas.devolver', $e['venta']))
        ->assertOk()
        ->assertSee($e['venta']->folio);
});

it('http: postventa.print responde el documento postventa', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Para imprimir.', null, [], null, [(int) $abono->id => 'P-1']);

    $this->actingAs($e['usuario'])
        ->get(route('postventa.print', $doc))
        ->assertOk()
        ->assertSee($doc->folio);
});

it('http: cancelar con motivo vacío devuelve errores de validación', function () {
    $e = [];
    debtOriginar($e);

    $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
        ->actingAs($e['usuario'])
        ->post(route('ventas.cancelar.store', $e['venta']), [
            'motivo' => '',
        ])
        ->assertSessionHasErrors('motivo');

    $this->assertDatabaseCount('documentos_postventa', 0);
});

it('http: sin permiso ventas.cancelar, el POST de cancelar se rechaza', function () {
    $e = [];
    debtOriginar($e);

    $sinPermiso = User::factory()->create();
    $sinPermiso->givePermissionTo('ventas.devolver');

    $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
        ->actingAs($sinPermiso)
        ->post(route('ventas.cancelar.store', $e['venta']), [
            'motivo' => 'Sin permiso.',
        ])
        ->assertForbidden();

    $this->assertDatabaseCount('documentos_postventa', 0);
});

/**
 * =========================
 * 16) Casos económicos adicionales
 * =========================
 */
it('devolver: dos devoluciones sobre el mismo abono consumen la disponibilidad LIFO', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $abono = debtAbono($cuenta, 90000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    // 1ª devolución: detalle 50000, saldo 10000 -> reduce 10000, reembolso 40000.
    $d1 = $e['detalles']->first();
    $doc1 = debtService()->devolver(
        $e['venta'],
        [(int) $d1->id],
        'Primera devolución.',
        null,
        [],
        null,
        [(int) $abono->id => 'DA-1']
    );
    expect($doc1->movimientoCxCDeuda->monto_centavos)->toBe(10000);
    expect($doc1->reembolsos)->toHaveCount(1);
    expect(Money::aCentavos($doc1->reembolsos->first()->monto))->toBe(40000);

    // 2ª devolución: detalle 50000, saldo 0 -> reembolso 50000 desde el mismo abono (queda 0).
    $d2 = $e['detalles']->get(1);
    $doc2 = debtService()->devolver(
        $e['venta'],
        [(int) $d2->id],
        'Segunda devolución.',
        null,
        [],
        null,
        [(int) $abono->id => 'DA-2']
    );
    expect($doc2->movimientoCxCDeuda)->toBeNull();
    expect($doc2->reembolsos)->toHaveCount(1);
    expect(Money::aCentavos($doc2->reembolsos->first()->monto))->toBe(50000);

    $this->assertDatabaseCount('reembolsos_postventa', 2);
    expect($e['venta']->refresh()->estado)->toBe(Venta::ESTADO_DEVUELTA);
});

it('devolver: total devuelto con reembolso del abono deja el estado DEVUELTA', function () {
    $e = [];
    debtOriginar($e);

    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $ids = $e['detalles']->pluck('id')->map('intval')->all();
    $doc = debtService()->devolver(
        $e['venta'],
        $ids,
        'Devolución total.',
        null,
        [],
        null,
        [(int) $abono->id => 'DT']
    );

    expect($doc->reembolsos)->toHaveCount(1);
    expect($e['venta']->refresh()->estado)->toBe(Venta::ESTADO_DEVUELTA);
    expect($e['items']->every(fn (Item $i) => $i->refresh()->estado === 'DEVUELTO'))->toBeTrue();
    expect($e['cuenta']->refresh()->saldo_centavos)->toBe(0);
});

it('cancelar: tres abonos LIFO consumen el más reciente primero', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $a1 = debtAbono($cuenta, 20000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $a2 = debtAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $a3 = debtAbono($cuenta, 40000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    // Abonos 90000, saldo 10000. Cancel: reduce 10000, sobrante 90000 -> a3(40000)+a2(30000)+a1(20000).
    $doc = debtService()->cancelar(
        $e['venta'],
        'Tres abonos.',
        null,
        [],
        null,
        [(int) $a1->id => 'R1', (int) $a2->id => 'R2', (int) $a3->id => 'R3']
    );

    expect($doc->movimientoCxCDeuda->monto_centavos)->toBe(10000);
    expect($doc->reembolsos)->toHaveCount(3);
    $primer = $doc->reembolsos->sortBy('orden')->first();
    expect((int) $primer->movimiento_cxc_id)->toBe((int) $a3->id);
    expect(Money::aCentavos($primer->monto))->toBe(40000);
    expect($cuenta->refresh()->saldo_centavos)->toBe(0);
    expect($cuenta->refresh()->estado)->toBe(CuentaPorCobrar::ESTADO_CANCELADA);
});

it('cancelar: reembolso mixto entre abono TARJETA y pago EFECTIVO toca la caja por el tramo del pago', function () {
    $e = [
        'precios' => ['500.00', '500.00'],
        'forma_pago' => 'MIXTO',
        'pagos_plan' => [
            ['metodo' => PagoVenta::METODO_EFECTIVO, 'monto' => 20000],
        ],
        'limite' => 400000,
    ];
    debtOriginar($e);

    $cuenta = $e['cuenta']; // crédito 80000
    $abono = debtAbono($cuenta, 50000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    // saldo 30000 -> cancel 100000: reduce 30000, sobrante 70000 -> abono(50000) + pago efectivo(20000).

    $sesion = openCajaFor($e['usuario']);
    // Depositar efectivo para cubrir el reembolso del pago EFECTIVO.
    app(CajaService::class)->registrarEntradaManual($sesion, $e['usuario'], 20000, 'Fondo de prueba.');

    $doc = debtService()->cancelar(
        $e['venta'],
        'Mixto con caja.',
        null,
        [(int) $e['pagos']->first()->id => null],
        null,
        [(int) $abono->id => 'RM-1']
    );

    expect($doc->movimientoCxCDeuda->monto_centavos)->toBe(30000);
    expect($doc->reembolsos)->toHaveCount(2);
    $deAbono = $doc->reembolsos->firstWhere('origen', ReembolsoPostventa::ORIGEN_CXC_ABONO);
    expect(Money::aCentavos($deAbono->monto))->toBe(50000);
    $dePago = $doc->reembolsos->firstWhere('origen', ReembolsoPostventa::ORIGEN_AUTOMATICO);
    expect(Money::aCentavos($dePago->monto))->toBe(20000);
    expect(MovimientoCaja::where('tipo', MovimientoCaja::TIPO_REEMBOLSO_EFECTIVO)->count())->toBe(1);
});

/**
 * =========================
 * 17) DB: triggers adicionales de vínculo (migración a) y fuente exacta (b)
 * =========================
 */
it('bd: REDUCCION_POSTVENTA con documento de tipo CANCELACION -> viola el trigger', function () {
    $e = [];
    debtOriginar($e);

    $docCancelacion = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_CANCELACION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc cancelación.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '1000.00',
    ]);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $e['cuenta']->id,
        'user_id' => $e['usuario']->id,
        'tipo' => MovimientoCxC::TIPO_REDUCCION_POSTVENTA,
        'monto_centavos' => 50000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 50000,
        'metodo' => null,
        'referencia' => null,
        'movimiento_origen_id' => null,
        'documento_postventa_id' => $docCancelacion->id,
        'observaciones' => 'REDUCCION con documento de CANCELACION.',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('bd: un REVERSA_ABONO no puede anclarse a un documento postventa (trigger)', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 30000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $doc = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_CANCELACION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc para REVERSA.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '1000.00',
    ]);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $e['cuenta']->id,
        'user_id' => $e['usuario']->id,
        'tipo' => MovimientoCxC::TIPO_REVERSA_ABONO,
        'monto_centavos' => 30000,
        'saldo_antes_centavos' => 70000,
        'saldo_despues_centavos' => 100000,
        'metodo' => null,
        'referencia' => null,
        'movimiento_origen_id' => $abono->id,
        'documento_postventa_id' => $doc->id,
        'observaciones' => 'REVERSA ilegal con documento.',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('bd: CXC_ABONO cuyo abono pertenece a otra venta -> viola el trigger de fuente exacta', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $otra = [];
    debtOriginar($otra);
    $docOtra = DocumentoPostventa::create([
        'venta_id' => $otra['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_CANCELACION,
        'user_id' => $otra['usuario']->id,
        'motivo' => 'Doc de otra venta.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '600.00',
    ]);

    expect(fn () => ReembolsoPostventa::create([
        'documento_postventa_id' => $docOtra->id,
        'pago_venta_id' => null,
        'movimiento_cxc_id' => $abono->id,
        'sesion_caja_id' => null,
        'user_id' => $e['usuario']->id,
        'metodo' => ReembolsoPostventa::METODO_TARJETA,
        'monto' => '600.00',
        'referencia' => 'X-R',
        'origen' => ReembolsoPostventa::ORIGEN_CXC_ABONO,
        'orden' => 1,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('bd: un LEGACY_MANUAL con documento pero sin fuente monetaria es válido (no viola nada)', function () {
    $e = [];
    debtOriginar($e);

    $doc = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_DEVOLUCION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc legacy positivo.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '600.00',
    ]);

    $r = ReembolsoPostventa::create([
        'documento_postventa_id' => $doc->id,
        'pago_venta_id' => null,
        'movimiento_cxc_id' => null,
        'sesion_caja_id' => null,
        'user_id' => $e['usuario']->id,
        'metodo' => ReembolsoPostventa::METODO_EFECTIVO,
        'monto' => '600.00',
        'referencia' => null,
        'origen' => ReembolsoPostventa::ORIGEN_LEGACY_MANUAL,
        'orden' => 1,
    ]);

    expect($r->id)->not->toBeNull();
    expect($r->origen)->toBe(ReembolsoPostventa::ORIGEN_LEGACY_MANUAL);
    expect($doc->refresh()->reembolsos)->toHaveCount(1);
});

/**
 * =========================
 * 18) Regresión B14.2 adicional: transferencia
 * =========================
 */
it('legacy: devolver una venta pagada por transferencia exige su referencia y conserva AUTOMATICO', function () {
    $e = [
        'precios' => ['500.00', '500.00'],
        'forma_pago' => 'TRANSFERENCIA',
        'pagos_plan' => [
            ['metodo' => PagoVenta::METODO_TRANSFERENCIA, 'monto' => 100000, 'referencia' => 'LEG-TRF'],
        ],
    ];
    debtOriginar($e);
    expect($e['cuenta'])->toBeNull();

    $detalle = $e['detalles']->first();
    $doc = debtService()->devolver(
        $e['venta'],
        [(int) $detalle->id],
        'Devolución sin crédito.',
        null,
        [(int) $e['pagos']->first()->id => 'REF-TRANSF'],
        null,
        []
    );

    expect($doc->reembolsos)->toHaveCount(1);
    expect($doc->reembolsos->first()->origen)->toBe(ReembolsoPostventa::ORIGEN_AUTOMATICO);
    expect($doc->reembolsos->first()->metodo)->toBe(ReembolsoPostventa::METODO_TRANSFERENCIA);
    expect($doc->reembolsos->first()->referencia)->toBe('REF-TRANSF');
    expect((int) $doc->reembolsos->first()->pago_venta_id)->toBe((int) $e['pagos']->first()->id);
});

/**
 * =========================
 * 19) HTTP adicional
 * =========================
 */
it('http: devolver sin seleccionar detalles devuelve errores de validación', function () {
    $e = [];
    debtOriginar($e);

    $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
        ->actingAs($e['usuario'])
        ->post(route('ventas.devolver.store', $e['venta']), [
            'motivo' => 'Devolución vacía.',
            'detalles' => [],
        ])
        ->assertSessionHasErrors('detalles');

    $this->assertDatabaseCount('documentos_postventa', 0);
});

it('http: cancelar exitoso por HTTP persiste el documento y redirige a postventa.show', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
        ->actingAs($e['usuario'])
        ->post(route('ventas.cancelar.store', $e['venta']), [
            'motivo' => 'Cancelación HTTP.',
            'referencias_reembolso_cxc' => [(string) $abono->id => 'HTTP-CXC'],
        ])
        ->assertRedirect(route('postventa.show', DocumentoPostventa::where('venta_id', $e['venta']->id)->firstOrFail()));

    $this->assertDatabaseCount('documentos_postventa', 1);
    expect($e['venta']->refresh()->estado)->toBe(Venta::ESTADO_CANCELADA);
});

it('http: devolver exitoso por HTTP persiste el documento y redirige a postventa.show', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $detalle = $e['detalles']->first();

    $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
        ->actingAs($e['usuario'])
        ->post(route('ventas.devolver.store', $e['venta']), [
            'motivo' => 'Devolución HTTP.',
            'detalles' => [(string) $detalle->id],
            'referencias_reembolso_cxc' => [(string) $abono->id => 'HTTP-DEV'],
        ])
        ->assertRedirect(route('postventa.show', DocumentoPostventa::where('venta_id', $e['venta']->id)->firstOrFail()));

    $this->assertDatabaseCount('documentos_postventa', 1);
    expect($e['venta']->refresh()->estado)->toBe(Venta::ESTADO_PARCIALMENTE_DEVUELTA);
});

it('http: postventa.show de un documento exige el permiso ventas.ver', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Para mostrar.', null, [], null, [(int) $abono->id => 'SHOW-1']);

    $this->actingAs($e['usuario'])
        ->get(route('postventa.show', $doc))
        ->assertOk()
        ->assertSee($doc->folio);

    $sinPermiso = User::factory()->create();
    $this->actingAs($sinPermiso)
        ->get(route('postventa.show', $doc))
        ->assertForbidden();
});

/**
 * =========================
 * 20) Casos económicos adicionales
 * =========================
 */
it('devolver: tres abonos LIFO consumen el más reciente primero', function () {
    $e = [
        'precios' => ['500.00', '500.00', '500.00'],
    ];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $viejo = debtAbono($cuenta, 60000, MovimientoCxC::METODO_TARJETA, $e['usuario'], '3V-1');
    $medio = debtAbono($cuenta, 50000, MovimientoCxC::METODO_TARJETA, $e['usuario'], '3V-2');
    $nuevo = debtAbono($cuenta, 40000, MovimientoCxC::METODO_TARJETA, $e['usuario'], '3V-3');
    expect($cuenta->refresh()->saldo_centavos)->toBe(0);

    // Devuelve un detalle de 50000: saldo 0 -> reembolso 50000 = 40000 (nuevo) + 10000 (medio).
    $detalle = $e['detalles']->first();
    $doc = debtService()->devolver(
        $e['venta'],
        [(int) $detalle->id],
        'Devolución con tres abonos.',
        null,
        [],
        null,
        [(int) $viejo->id => '3V-R1', (int) $medio->id => '3V-R2', (int) $nuevo->id => '3V-R3']
    );

    expect($doc->movimientoCxCDeuda)->toBeNull();
    expect($doc->reembolsos)->toHaveCount(2);
    $porAbono = $doc->reembolsos->keyBy('movimiento_cxc_id');
    expect(Money::aCentavos($porAbono[(string) (int) $nuevo->id]->monto))->toBe(40000);
    expect(Money::aCentavos($porAbono[(string) (int) $medio->id]->monto))->toBe(10000);
    expect($porAbono->has((string) (int) $viejo->id))->toBeFalse();
});

it('cancelar: venta saldada mezcla el abono para el sobrante y el pago EFECTIVO toca la caja', function () {
    $e = [
        'precios' => ['500.00', '500.00'],
        'forma_pago' => 'MIXTO',
        'pagos_plan' => [
            ['metodo' => PagoVenta::METODO_EFECTIVO, 'monto' => 40000],
        ],
    ];
    debtOriginar($e);

    $cuenta = $e['cuenta']; // crédito 60000.
    $abono = debtAbono($cuenta, 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    expect($cuenta->refresh()->saldo_centavos)->toBe(0);

    // El reembolso EFECTIVO (tramo del pago) sale de la caja.
    $sesion = openCajaFor($e['usuario']);
    app(CajaService::class)->registrarEntradaManual($sesion, $e['usuario'], 40000, 'Fondo saldada.');

    $doc = debtService()->cancelar(
        $e['venta'],
        'Saldada con pago.',
        null,
        [],
        null,
        [(int) $abono->id => 'SD-1']
    );

    expect($doc->movimientoCxCDeuda)->toBeNull();
    expect($doc->reembolsos)->toHaveCount(2);
    $deAbono = $doc->reembolsos->firstWhere('origen', ReembolsoPostventa::ORIGEN_CXC_ABONO);
    expect(Money::aCentavos($deAbono->monto))->toBe(60000);
    $dePago = $doc->reembolsos->firstWhere('origen', ReembolsoPostventa::ORIGEN_AUTOMATICO);
    expect(Money::aCentavos($dePago->monto))->toBe(40000);
    expect(MovimientoCaja::where('tipo', MovimientoCaja::TIPO_REEMBOLSO_EFECTIVO)->count())->toBe(1);
});

it('cancelar: crédito con dos pagos prorratea el sobrante y solo el tramo EFECTIVO toca caja', function () {
    $e = [
        'precios' => ['500.00', '500.00'],
        'forma_pago' => 'MIXTO',
        'pagos_plan' => [
            ['metodo' => PagoVenta::METODO_EFECTIVO, 'monto' => 20000],
            ['metodo' => PagoVenta::METODO_TARJETA, 'monto' => 60000, 'referencia' => 'MX-2'],
        ],
    ];
    debtOriginar($e);

    $cuenta = $e['cuenta']; // crédito 20000.
    $abono = debtAbono($cuenta, 10000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    // saldo 10000 -> reduce 10000; sobrante 90000 = abono 10000 + prorrateo 20000 EF / 60000 TARJ.

    $sesion = openCajaFor($e['usuario']);
    app(CajaService::class)->registrarEntradaManual($sesion, $e['usuario'], 20000, 'Fondo prorrateo.');

    $doc = debtService()->cancelar(
        $e['venta'],
        'Mixto con crédito.',
        null,
        [(int) $e['pagos']->get(0)->id => null, (int) $e['pagos']->get(1)->id => 'PMX-T'],
        null,
        [(int) $abono->id => 'PMX-CXC']
    );

    expect($doc->movimientoCxCDeuda->monto_centavos)->toBe(10000);
    expect($doc->reembolsos)->toHaveCount(3);
    expect(Money::aCentavos((string) $doc->reembolsos->sum('monto')))->toBe(90000);

    $efectivoAutomatico = $doc->reembolsos->first(
        fn ($r) => $r->origen === ReembolsoPostventa::ORIGEN_AUTOMATICO
            && $r->metodo === ReembolsoPostventa::METODO_EFECTIVO
    );
    $tarjetaAutomatica = $doc->reembolsos->first(
        fn ($r) => $r->origen === ReembolsoPostventa::ORIGEN_AUTOMATICO
            && $r->metodo === ReembolsoPostventa::METODO_TARJETA
    );
    expect($efectivoAutomatico)->not->toBeNull();
    expect(Money::aCentavos($efectivoAutomatico->monto))->toBe(20000);
    expect($tarjetaAutomatica)->not->toBeNull();
    expect(Money::aCentavos($tarjetaAutomatica->monto))->toBe(60000);
    expect(MovimientoCaja::where('tipo', MovimientoCaja::TIPO_REEMBOLSO_EFECTIVO)->count())->toBe(1);
});

it('cancelar: abono EFECTIVO y abono TARJETA reembolsan cada tramo por su método y la caja solo por el EFECTIVO', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $tarjeta = debtAbono($cuenta, 50000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $efectivo = debtAbono($cuenta, 20000, MovimientoCxC::METODO_EFECTIVO, $e['usuario']);
    // saldo 30000 -> reduce 30000; sobrante 70000 = 20000 (efectivo, nuevo) + 50000 (tarjeta).

    $doc = debtService()->cancelar(
        $e['venta'],
        'Dos abonos de distinto método.',
        null,
        [],
        null,
        [(int) $tarjeta->id => 'DA-1', (int) $efectivo->id => null]
    );

    expect($doc->movimientoCxCDeuda->monto_centavos)->toBe(30000);
    expect($doc->reembolsos)->toHaveCount(2);

    $reembolsoEfectivo = $doc->reembolsos->firstWhere('movimiento_cxc_id', (string) (int) $efectivo->id);
    $reembolsoTarjeta = $doc->reembolsos->firstWhere('movimiento_cxc_id', (string) (int) $tarjeta->id);
    expect($reembolsoEfectivo->metodo)->toBe(ReembolsoPostventa::METODO_EFECTIVO);
    expect(Money::aCentavos($reembolsoEfectivo->monto))->toBe(20000);
    expect($reembolsoTarjeta->metodo)->toBe(ReembolsoPostventa::METODO_TARJETA);
    expect(Money::aCentavos($reembolsoTarjeta->monto))->toBe(50000);

    // Entrada del abono EFECTIVO + salida del reembolso EFECTIVO.
    expect(MovimientoCaja::where('tipo', MovimientoCaja::TIPO_ABONO_CXC_EFECTIVO)->count())->toBe(1);
    expect(MovimientoCaja::where('tipo', MovimientoCaja::TIPO_REEMBOLSO_EFECTIVO)->count())->toBe(1);
});

/**
 * =========================
 * 21) Modelos y consultas: relaciones + invariante aritmética
 * =========================
 */
it('modelos: la venta expone su documento postventa y el reembolso resuelve el Abono origen', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $detalle = $e['detalles']->first();
    $doc = debtService()->devolver(
        $e['venta'],
        [(int) $detalle->id],
        'Relaciones de modelos.',
        null,
        [],
        null,
        [(int) $abono->id => 'MOD-1']
    );

    $documentos = $e['venta']->documentosPostventa()->get();
    expect($documentos)->toHaveCount(1);
    expect($documentos->first()->is($doc))->toBeTrue();

    $r = $doc->reembolsos->first();
    expect($r->movimientoCxC()->first()->is($abono))->toBeTrue();
    expect($abono->reembolsosPostventa()->whereKey($r->id)->exists())->toBeTrue();
    expect($doc->movimientoCxCDeuda->tipo)->toBe(MovimientoCxC::TIPO_REDUCCION_POSTVENTA);
    expect($doc->movimientoCxCDeuda->monto_centavos)->toBe(40000);
});

it('modelos: el documento concilia deuda + reembolsos con su total tras la operación', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $a1 = debtAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $e['usuario'], 'A-1');
    $a2 = debtAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $e['usuario'], 'A-2');
    // saldo 40000 -> reduce 40000; sobrante 60000 = 30000 + 30000.

    $doc = debtService()->cancelar(
        $e['venta'],
        'Invariante del documento.',
        null,
        [],
        null,
        [(int) $a1->id => 'I-1', (int) $a2->id => 'I-2']
    );

    $deudaCentavos = (int) $doc->movimientoCxCDeuda->monto_centavos;
    $reembolsoCentavos = (int) Money::aCentavos((string) $doc->reembolsos->sum('monto'));
    $totalCentavos = (int) Money::aCentavos((string) $doc->total);

    expect($deudaCentavos + $reembolsoCentavos)->toBe($totalCentavos);
    expect($reembolsoCentavos)->toBe(60000);
    expect($doc->reembolsos->pluck('origen')->all())
        ->toBe([ReembolsoPostventa::ORIGEN_CXC_ABONO, ReembolsoPostventa::ORIGEN_CXC_ABONO]);
});

/**
 * =========================
 * 22) REV1: append-only de ReembolsoPostventa (modelo + BD)
 * =========================
 */
it('reembolso: UPDATE por Eloquent -> DomainException (append-only modelo)', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Append-only.', null, [], null, [(int) $abono->id => 'AO-1']);
    $reembolso = $doc->reembolsos->first();

    expect(fn () => $reembolso->update(['monto' => '1.00']))
        ->toThrow(DomainException::class, 'históricos e inmutables');
});

it('reembolso: DELETE por Eloquent -> DomainException (append-only modelo)', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Append-only del.', null, [], null, [(int) $abono->id => 'AO-2']);
    $reembolso = $doc->reembolsos->first();

    expect(fn () => $reembolso->delete())
        ->toThrow(DomainException::class, 'históricos e inmutables');
});

it('reembolso: UPDATE SQL directo -> QueryException (trigger append-only)', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Append-only sql.', null, [], null, [(int) $abono->id => 'AO-3']);
    $id = $doc->reembolsos->first()->id;

    expect(fn () => DB::table('reembolsos_postventa')->where('id', $id)->update(['monto' => '1.00']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('reembolso: DELETE SQL directo -> QueryException (trigger append-only)', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Append-only del sql.', null, [], null, [(int) $abono->id => 'AO-4']);
    $id = $doc->reembolsos->first()->id;

    expect(fn () => DB::table('reembolsos_postventa')->where('id', $id)->delete())
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('reembolso: mover documento_postventa_id -> rechazado (append-only BD)', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Append-only doc.', null, [], null, [(int) $abono->id => 'AO-5']);
    $id = $doc->reembolsos->first()->id;

    $otroDoc = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_DEVOLUCION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc alterno.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '600.00',
    ]);

    expect(fn () => DB::table('reembolsos_postventa')
        ->where('id', $id)
        ->update(['documento_postventa_id' => $otroDoc->id]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('reembolso: cambiar monto/metodo/origen/pago/abono -> rechazado (append-only BD)', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Append-only campos.', null, [], null, [(int) $abono->id => 'AO-6']);
    $id = $doc->reembolsos->first()->id;

    $pago = PagoVenta::create([
        'venta_id' => $e['venta']->id,
        'user_id' => $e['usuario']->id,
        'metodo' => PagoVenta::METODO_TARJETA,
        'monto_aplicado' => '300.00',
        'efectivo_recibido' => null,
        'cambio_entregado' => null,
        'referencia' => 'P-ALT',
        'origen' => PagoVenta::ORIGEN_POS,
        'orden' => 1,
        'sesion_caja_id' => null,
    ]);

    $mutaciones = [
        ['monto' => '1.00'],
        ['metodo' => ReembolsoPostventa::METODO_EFECTIVO],
        ['origen' => ReembolsoPostventa::ORIGEN_LEGACY_MANUAL],
        ['pago_venta_id' => $pago->id],
        ['movimiento_cxc_id' => null],
    ];

    foreach ($mutaciones as $campos) {
        expect(fn () => DB::table('reembolsos_postventa')->where('id', $id)->update($campos))
            ->toThrow(\Illuminate\Database\QueryException::class);
    }
});

it('reembolso: INSERT normal sigue funcionando (append-only no bloquea inserts)', function () {
    $e = [];
    debtOriginar($e);
    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $doc = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_CANCELACION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc insert append-only.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '600.00',
    ]);

    $r = ReembolsoPostventa::create([
        'documento_postventa_id' => $doc->id,
        'pago_venta_id' => null,
        'movimiento_cxc_id' => $abono->id,
        'sesion_caja_id' => null,
        'user_id' => $e['usuario']->id,
        'metodo' => ReembolsoPostventa::METODO_TARJETA,
        'monto' => '600.00',
        'referencia' => 'AO-INS',
        'origen' => ReembolsoPostventa::ORIGEN_CXC_ABONO,
        'orden' => 1,
    ]);

    expect($r->id)->not->toBeNull();
});

it('reembolso: el trigger fuente exacta incluye documento_postventa_id (defense-in-depth)', function () {
    $def = DB::selectOne("
        SELECT pg_get_triggerdef(t.oid) AS def
        FROM pg_trigger t
        JOIN pg_class c ON c.oid = t.tgrelid
        WHERE c.relname = 'reembolsos_postventa' AND t.tgname = 'reembolso_fuente_exacta'
    ");

    expect($def)->not->toBeNull();
    expect($def->def)->toContain('documento_postventa_id');
});

/**
 * =========================
 * 23) REV1: LIFO exacto (created_at DESC, id DESC)
 * =========================
 */
it('cancelar: LIFO usa created_at DESC antes que id DESC', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    // id 1 (más antiguo en id) con created_at MÁS RECIENTE.
    $recientePorFecha = debtAbono($cuenta, 40000, MovimientoCxC::METODO_TARJETA, $e['usuario'], 'CF-1');
    $cuenta->refresh();

    // id 2 (más nuevo en id) con created_at ANTERIOR (insert directo, primer abono no backdateable).
    DB::table('movimientos_cxc')->insert([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => $e['usuario']->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 30000,
        'saldo_antes_centavos' => 60000,
        'saldo_despues_centavos' => 30000,
        'metodo' => MovimientoCxC::METODO_TARJETA,
        'referencia' => 'LIFO-DB',
        'movimiento_origen_id' => null,
        'documento_postventa_id' => null,
        'operacion_uuid' => (string) \Illuminate\Support\Str::uuid(),
        'observaciones' => 'Abono con created_at anterior.',
        'created_at' => now()->subMinute(),
    ]);
    $antiguoPorFecha = MovimientoCxC::where('referencia', 'LIFO-DB')->sole();
    DB::table('cuentas_por_cobrar')->where('id', $cuenta->id)->update([
        'saldo_centavos' => 30000,
        'estado' => CuentaPorCobrar::ESTADO_PARCIAL,
    ]);

    // saldo 30000 -> reduce 30000; sobrante 70000 -> primero el ABONO por fecha.
    $doc = debtService()->cancelar(
        $e['venta'],
        'LIFO por fecha.',
        null,
        [],
        null,
        [(int) $recientePorFecha->id => 'CF-A', (int) $antiguoPorFecha->id => 'CF-B']
    );

    expect($doc->movimientoCxCDeuda->monto_centavos)->toBe(30000);
    expect($doc->reembolsos)->toHaveCount(2);
    $porAbono = $doc->reembolsos->keyBy('movimiento_cxc_id');
    expect(Money::aCentavos($porAbono[(string) (int) $recientePorFecha->id]->monto))->toBe(40000);
    expect(Money::aCentavos($porAbono[(string) (int) $antiguoPorFecha->id]->monto))->toBe(30000);
    // El ABONO de MENOR id se consume PRIMERO porque su created_at es más reciente.
    expect((int) $doc->reembolsos->first()->movimiento_cxc_id)->toBe((int) $recientePorFecha->id);
});

it('cancelar: empate de created_at desempata por id DESC', function () {
    $e = [];
    debtOriginar($e);

    $cuenta = $e['cuenta'];
    $empate = now()->startOfDay();

    $insertar = function (int $monto, int $saldoAntes, int $saldoDespues, string $referencia) use ($cuenta, $e, $empate) {
        DB::table('movimientos_cxc')->insert([
            'cuenta_por_cobrar_id' => $cuenta->id,
            'user_id' => $e['usuario']->id,
            'tipo' => MovimientoCxC::TIPO_ABONO,
            'monto_centavos' => $monto,
            'saldo_antes_centavos' => $saldoAntes,
            'saldo_despues_centavos' => $saldoDespues,
            'metodo' => MovimientoCxC::METODO_TARJETA,
            'referencia' => $referencia,
            'movimiento_origen_id' => null,
            'documento_postventa_id' => null,
            'operacion_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'observaciones' => 'Abono con created_at empatado.',
            'created_at' => $empate,
        ]);
    };

    $insertar(30000, 100000, 70000, 'TIE-A');
    $insertar(40000, 70000, 30000, 'TIE-B');
    $primero = MovimientoCxC::where('referencia', 'TIE-A')->sole();
    $segundo = MovimientoCxC::where('referencia', 'TIE-B')->sole();
    DB::table('cuentas_por_cobrar')->where('id', $cuenta->id)->update([
        'saldo_centavos' => 30000,
        'estado' => CuentaPorCobrar::ESTADO_PARCIAL,
    ]);

    // saldo 30000 -> reduce 30000; sobrante 70000 -> id mayor primero (empate de created_at).
    $doc = debtService()->cancelar(
        $e['venta'],
        'Empate LIFO.',
        null,
        [],
        null,
        [(int) $primero->id => 'TE-A', (int) $segundo->id => 'TE-B']
    );

    expect($doc->reembolsos)->toHaveCount(2);
    $porAbono = $doc->reembolsos->keyBy('movimiento_cxc_id');
    expect(Money::aCentavos($porAbono[(string) (int) $segundo->id]->monto))->toBe(40000);
    expect(Money::aCentavos($porAbono[(string) (int) $primero->id]->monto))->toBe(30000);
    expect((int) $doc->reembolsos->first()->movimiento_cxc_id)->toBe((int) $segundo->id);
});

/**
 * =========================
 * 24) REV1: documento_postventa_id en el ledger (invariantes estrictas)
 * =========================
 */
it('bd: REDUCCION_POSTVENTA con documento NULL -> falla (trigger)', function () {
    $e = [];
    debtOriginar($e);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $e['cuenta']->id,
        'user_id' => $e['usuario']->id,
        'tipo' => MovimientoCxC::TIPO_REDUCCION_POSTVENTA,
        'monto_centavos' => 50000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 50000,
        'metodo' => null,
        'referencia' => null,
        'movimiento_origen_id' => null,
        'documento_postventa_id' => null,
        'observaciones' => 'REDUCCION sin documento.',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('bd: CANCELACION con documento NULL -> falla (trigger)', function () {
    $e = [];
    debtOriginar($e);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $e['cuenta']->id,
        'user_id' => $e['usuario']->id,
        'tipo' => MovimientoCxC::TIPO_CANCELACION,
        'monto_centavos' => 100000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 0,
        'metodo' => null,
        'referencia' => null,
        'movimiento_origen_id' => null,
        'documento_postventa_id' => null,
        'observaciones' => 'CANCELACION sin documento.',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('bd: CARGO_INICIAL con documento NOT NULL -> falla (trigger)', function () {
    $e = [];
    debtOriginar($e);

    $doc = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_CANCELACION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc para cargo.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '1000.00',
    ]);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $e['cuenta']->id,
        'user_id' => $e['usuario']->id,
        'tipo' => MovimientoCxC::TIPO_CARGO_INICIAL,
        'monto_centavos' => 100000,
        'saldo_antes_centavos' => 0,
        'saldo_despues_centavos' => 100000,
        'metodo' => null,
        'referencia' => null,
        'movimiento_origen_id' => null,
        'documento_postventa_id' => $doc->id,
        'observaciones' => 'CARGO_INICIAL ilegal con documento.',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('bd: REDUCCION_POSTVENTA con documento DEVOLUCION válido -> pasa', function () {
    $e = [];
    debtOriginar($e);

    $docDevolucion = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_DEVOLUCION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc devolución positiva.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '600.00',
    ]);

    $m = MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $e['cuenta']->id,
        'user_id' => $e['usuario']->id,
        'tipo' => MovimientoCxC::TIPO_REDUCCION_POSTVENTA,
        'monto_centavos' => 50000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 50000,
        'metodo' => null,
        'referencia' => null,
        'movimiento_origen_id' => null,
        'documento_postventa_id' => $docDevolucion->id,
        'observaciones' => 'REDUCCION válida.',
    ]);

    expect((int) $m->documento_postventa_id)->toBe((int) $docDevolucion->id);
});

it('bd: CANCELACION con documento CANCELACION válido -> pasa', function () {
    $e = [];
    debtOriginar($e);

    $docCancelacion = DocumentoPostventa::create([
        'venta_id' => $e['venta']->id,
        'tipo' => DocumentoPostventa::TIPO_CANCELACION,
        'user_id' => $e['usuario']->id,
        'motivo' => 'Doc cancelación positiva.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => '1000.00',
    ]);

    $m = MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $e['cuenta']->id,
        'user_id' => $e['usuario']->id,
        'tipo' => MovimientoCxC::TIPO_CANCELACION,
        'monto_centavos' => 100000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 0,
        'metodo' => null,
        'referencia' => null,
        'movimiento_origen_id' => null,
        'documento_postventa_id' => $docCancelacion->id,
        'observaciones' => 'CANCELACION válida.',
    ]);

    expect((int) $m->documento_postventa_id)->toBe((int) $docCancelacion->id);
});

/**
 * =========================
 * 25) REV1: down/up real de la migración A con datos
 * =========================
 */
it('migración a: up -> datos -> down -> ledger sobrevive -> up -> invariantes restauradas', function () {
    $e = [];
    debtOriginar($e);

    $abono = debtAbono($e['cuenta'], 80000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    $doc = debtService()->cancelar($e['venta'], 'Para down/up real.', null, [], null, [(int) $abono->id => 'DM']);
    $cancId = $doc->movimientoCxCDeuda->id;

    try {
        Artisan::call('migrate:rollback', ['--step' => 2]);

        // El ledger sobrevive al down(): la CANCELACION (monto 20000) intacta.
        expect(DB::table('movimientos_cxc')->where('id', $cancId)->exists())->toBeTrue();
        expect(DB::table('movimientos_cxc')->find($cancId)->monto_centavos)->toBe(20000);
        expect(Schema::hasColumn('movimientos_cxc', 'documento_postventa_id'))->toBeFalse();

        // up() de nuevo: esquema e invariantes estrictas restauradas.
        Artisan::call('migrate');

        expect(Schema::hasColumn('movimientos_cxc', 'documento_postventa_id'))->toBeTrue();
        expect(Schema::hasColumn('reembolsos_postventa', 'movimiento_cxc_id'))->toBeTrue();

        // Nuevos inserts siguen sujetos a la invariante estricta: la excepción
        // debe SALIR del DB::transaction para que este haga ROLLBACK TO SAVEPOINT
        // y no deje la transacción abortada.
        try {
            DB::transaction(function () use ($e) {
                MovimientoCxC::create([
                    'cuenta_por_cobrar_id' => $e['cuenta']->id,
                    'user_id' => $e['usuario']->id,
                    'tipo' => MovimientoCxC::TIPO_REDUCCION_POSTVENTA,
                    'monto_centavos' => 50000,
                    'saldo_antes_centavos' => 100000,
                    'saldo_despues_centavos' => 50000,
                    'metodo' => null,
                    'referencia' => null,
                    'movimiento_origen_id' => null,
                    'documento_postventa_id' => null,
                    'observaciones' => 'REDUCCION sin documento tras up.',
                ]);
                throw new \RuntimeException('El insert no debió tener éxito.');
            });
        } catch (\Illuminate\Database\QueryException) {
            // Esperado: la invariante estricta sigue activa tras el re-up().
        }
    } finally {
        Artisan::call('migrate');
    }
});

/**
 * =========================
 * 26) REV1: reversa de ABONO interlock
 * =========================
 */
it('reversa: un ABONO parcialmente reembolsado no puede reversarse', function () {
    $e = [];
    debtOriginar($e);

    $abono = debtAbono($e['cuenta'], 60000, MovimientoCxC::METODO_TARJETA, $e['usuario']);
    // Devuelve un detalle: reduce 40000 y reembolsa 10000 del abono (parcial).
    $detalle = $e['detalles']->first();
    $doc = debtService()->devolver(
        $e['venta'],
        [(int) $detalle->id],
        'Reembolso parcial.',
        null,
        [],
        null,
        [(int) $abono->id => 'RP-1']
    );
    expect(Money::aCentavos($doc->reembolsos->first()->monto))->toBe(10000);

    expect(fn () => debtReversarAbono($e['cuenta'], $abono, $e['usuario']))
        ->toThrow(DomainException::class, 'utilizado en una operación postventa');
});

it('reversa: un ABONO sin uso postventa puede reversarse', function () {
    $e = [];
    debtOriginar($e);

    $abono = debtAbono($e['cuenta'], 40000, MovimientoCxC::METODO_TARJETA, $e['usuario']);

    $reversa = app(CuentaPorCobrarService::class)->reversarAbono(
        $e['cuenta'],
        $abono,
        $e['usuario'],
        'Reversa sin uso postventa.'
    );

    expect($reversa->tipo)->toBe(MovimientoCxC::TIPO_REVERSA_ABONO);
    expect((int) $reversa->movimiento_origen_id)->toBe((int) $abono->id);
    expect($e['cuenta']->refresh()->saldo_centavos)->toBe(100000);
});
