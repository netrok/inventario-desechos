<?php

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\DocumentoPostventa;
use App\Models\Item;
use App\Models\MovimientoCaja;
use App\Models\PagoVenta;
use App\Models\SesionCaja;
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

    foreach ([
        'dashboard.ver',
        'ventas.ver', 'ventas.crear',
        'ventas.cancelar', 'ventas.devolver', 'items.ver',
        'cajas.ver', 'cajas.configurar', 'cajas.abrir', 'cajas.operar',
        'cajas.movimientos', 'cajas.cerrar', 'cajas.ver_todas', 'cajas.ajustar',
        'cajas.entrada', 'cajas.retiro',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Operador de caja con todos los permisos B14 + ventas/postventa.
 */
function cajaAdmin(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        'dashboard.ver',
        'ventas.ver', 'ventas.crear', 'ventas.cancelar', 'ventas.devolver', 'items.ver',
        'cajas.ver', 'cajas.configurar', 'cajas.abrir', 'cajas.operar',
        'cajas.movimientos', 'cajas.cerrar', 'cajas.ver_todas', 'cajas.ajustar',
        'cajas.entrada', 'cajas.retiro',
    ]);

    return $user;
}

/**
 * Operador que solo ve su propio historial (cajas.ver + cajas.movimientos).
 * Sin escritura de efectivo (entrada/retiro/ajuste): representa al rol Ventas.
 */
function cajaOperador(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['cajas.ver', 'cajas.movimientos', 'cajas.abrir', 'cajas.operar', 'cajas.cerrar']);

    return $user;
}

/**
 * Registrador de efectivo sin cajas.ver_todas: puede escribir entrada/retiro
 * en su propia sesión pero no en la de otro operador.
 */
function cajaRegistrador(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['cajas.ver', 'cajas.movimientos', 'cajas.abrir', 'cajas.operar', 'cajas.cerrar', 'cajas.entrada', 'cajas.retiro']);

    return $user;
}

function cajaFisica(bool $activa = true, ?User $asignado = null): Caja
{
    // B14.3.1 FIX 3: la caja ACTIVA exige operador (CHECK a nivel BD). Si el
    // test pide una caja activa sin operador explícito, se asigna uno por
    // defecto para que las escenas B14 heredadas sigan siendo válidas.
    $asignado ??= ($activa ? cajaOperador() : null);

    return Caja::create([
        'nombre' => 'Caja Principal B14',
        'activa' => $activa,
        'descripcion' => 'Caja de prueba.',
        'usuario_asignado_id' => $asignado?->id,
    ]);
}

function cajaItem(float $precio = 100.0): Item
{
    return Item::create(['estado' => 'DISPONIBLE', 'precio' => $precio]);
}

function cajaCliente(): Cliente
{
    return Cliente::create(['nombre' => 'Cliente Caja', 'tipo' => 'PERSONA']);
}

/**
 * Venta directa (Venta + VentaDetalle) sin pasar por el POS; útil para
 * ejercitar el servicio de cobro/cierre/esperado aislado del carrito.
 */
function ventaCaja(User $user, float $precio): Venta
{
    $venta = Venta::create([
        'user_id' => $user->id,
        'cliente_id' => cajaCliente()->id,
        'total' => Money::aPrecio(Money::aCentavos($precio)),
        'forma_pago' => 'EFECTIVO',
    ]);

    $venta->detalles()->create([
        'item_id' => cajaItem($precio)->id,
        'precio' => Money::aPrecio(Money::aCentavos($precio)),
    ]);

    return $venta;
}

/**
 * Venta YA VENDIDA (items VENDIDO + detalles) para operaciones postventa.
 */
function ventaVendidaCaja(User $user, array $precios): Venta
{
    $items = collect($precios)->map(
        fn (float $p) => Item::create(['estado' => 'VENDIDO', 'precio' => $p])
    );

    $total = array_sum(array_map(fn (float $p) => Money::aCentavos($p), $precios));

    $venta = Venta::create([
        'user_id' => $user->id,
        'total' => Money::aPrecio($total),
        'forma_pago' => 'EFECTIVO',
    ]);

    foreach ($items as $item) {
        $venta->detalles()->create(['item_id' => $item->id, 'precio' => $item->precio]);
    }

    return $venta;
}

/**
 * Cobra una venta directa vía servicio (mismos pesos string que el POS).
 */
function cobrarCaja(Venta $venta, SesionCaja $sesion, User $user, array $pagos, int $totalCentavos): void
{
    app(CajaService::class)->cobrarVenta($venta, $sesion, $user, $pagos, $totalCentavos);
}

/**
 * =================
 * APERTURA DE SESIÓN
 * =================
 */
it('abre una sesión ABIERTA con folio COR-XXXXXX y fondo exacto', function () {
    $user = cajaAdmin();
    $caja = cajaFisica(true, $user);

    $sesion = app(CajaService::class)->abrirSesion($caja, $user, Money::aCentavos('1500.25'));

    expect($sesion->folio)->toMatch('/^COR-\d{6}$/');
    expect($sesion->estado)->toBe(SesionCaja::ESTADO_ABIERTA);
    expect($sesion->caja_id)->toBe($caja->id);
    expect($sesion->user_id_apertura)->toBe($user->id);
    expect($sesion->fondo_inicial)->toBe('1500.25');
});

it('una caja física no admite dos sesiones ABIERTAS simultáneas', function () {
    $user = cajaAdmin();
    $caja = cajaFisica(true, $user);
    app(CajaService::class)->abrirSesion($caja, $user, 0);

    expect(fn () => app(CajaService::class)->abrirSesion($caja, $user, 0))
        ->toThrow(DomainException::class, 'ya tiene una sesión abierta');
});

it('un operador no puede tener dos sesiones ABIERTAS', function () {
    $user = cajaAdmin();
    $otro = cajaRegistrador();
    $caja = cajaFisica(true, $user);
    app(CajaService::class)->abrirSesion($caja, $user, 0);

    // B14.3.1: un usuario está asignado a UNA sola caja, así que no puede
    // abrir la caja ajena.
    $cajaAjena = cajaFisica(true, $otro);
    expect(fn () => app(CajaService::class)->abrirSesion($cajaAjena, $user, 0))
        ->toThrow(DomainException::class, 'no está asignada a tu usuario');

    // La caja propia tampoco admite una segunda sesión abierta.
    expect(fn () => app(CajaService::class)->abrirSesion($caja, $user, 0))
        ->toThrow(DomainException::class, 'ya tiene una sesión abierta');

    expect(SesionCaja::abiertas()->count())->toBe(1);
});

it('el índice único parcial de BD impide dos sesiones ABIERTAS del mismo operador', function () {
    $user = cajaAdmin();
    $caja = cajaFisica(true, $user);
    app(CajaService::class)->abrirSesion($caja, $user, 0);

    // Bypass del servicio para ejercitar la barrera de BD (defensa en
    // profundidad): un segundo ABIERTA para el mismo operador debe violar el
    // índice único parcial sesiones_caja_operador_abierta_unique.
    expect(fn () => SesionCaja::create([
        'caja_id' => cajaFisica(true, User::factory()->create())->id,
        'user_id_apertura' => $user->id,
        'fondo_inicial' => 0,
        'estado' => SesionCaja::ESTADO_ABIERTA,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('rechaza abrir una caja inactiva', function () {
    $user = cajaAdmin();

    expect(fn () => app(CajaService::class)->abrirSesion(cajaFisica(false), $user, 0))
        ->toThrow(DomainException::class, 'inactiva');
});

it('rechaza abrir una caja no asignada a tu usuario', function () {
    $user = cajaAdmin();
    $otro = cajaAdmin();
    $caja = cajaFisica(true, $otro);

    expect(fn () => app(CajaService::class)->abrirSesion($caja, $user, 0))
        ->toThrow(DomainException::class, 'no está asignada a tu usuario');
});

it('B14.3 revalida bajo lock una caja desactivada tras un escenario STALE', function () {
    $user = cajaAdmin();

    // Instancia local con activa=true (el escenario previo a la desactivación).
    $caja = cajaFisica(true, $user);
    expect($caja->activa)->toBeTrue();

    // Otra transacción desactiva la misma caja sin refrescar la instancia local.
    Caja::where('id', $caja->id)->update(['activa' => false]);

    // $caja sigue representando el estado STALE (activa=true en memoria).
    expect($caja->activa)->toBeTrue();

    // Aunque la instancia exterior sea stale, la fila bloqueada es la inactiva.
    expect(fn () => app(CajaService::class)->abrirSesion($caja, $user, 0))
        ->toThrow(DomainException::class, 'inactiva');

    // No debe haberse creado ninguna sesión.
    expect(SesionCaja::count())->toBe(0);
});

it('el formulario y POST de apertura exigen el permiso cajas.abrir', function () {
    $user = cajaOperador();
    $user->revokePermissionTo('cajas.abrir');

    $this->actingAs($user)->get(route('cajas.abrir'))->assertForbidden();
    $this->actingAs($user)->post(route('cajas.abrir.store'), ['caja_id' => 1, 'fondo_inicial' => '0.00'])->assertForbidden();
});

it('abre por HTTP, genera folio COR y redirige a los movimientos', function () {
    $user = cajaAdmin();
    $caja = cajaFisica(true, $user);

    $this->actingAs($user)
        ->post(route('cajas.abrir.store'), [
            'fondo_inicial' => '500.00',
            'observaciones_apertura' => 'Apertura de prueba.',
        ])
        ->assertRedirect(route('cajas.movimientos', SesionCaja::first()))
        ->assertSessionHasNoErrors();

    $sesion = SesionCaja::first();
    expect($sesion)->not->toBeNull();
    expect($sesion->folio)->toMatch('/^COR-\d{6}$/');
    expect($sesion->estado)->toBe(SesionCaja::ESTADO_ABIERTA);
    expect($sesion->fondo_inicial)->toBe('500.00');
});

it('valida un fondo inicial negativo en la apertura', function () {
    $user = cajaAdmin();
    $caja = cajaFisica(true, $user);

    $this->actingAs($user)
        ->post(route('cajas.abrir.store'), [
            'fondo_inicial' => '-10.00',
        ])
        ->assertSessionHasErrors('fondo_inicial');

    expect(SesionCaja::count())->toBe(0);
});

/**
 * =================
 * COBROS, CAMBIO Y PAGOS COMBINADOS
 * =================
 */
it('una venta en TARJETA no genera movimientos de efectivo', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user);

    $item = cajaItem(200.0);
    $this->session(['pos.cart' => [$item->id], 'pos.cliente_id' => cajaCliente()->id]);

    $this->actingAs($user)
        ->post(route('pos.checkout'), array_merge([
            'items' => [$item->id],
        ], pagosMetodo(200.0, PagoVenta::METODO_TARJETA)))
        ->assertSessionHasNoErrors();

    $venta = Venta::first();
    expect($venta->forma_pago)->toBe(PagoVenta::METODO_TARJETA);
    expect($venta->pagos->count())->toBe(1);
    expect(MovimientoCaja::where('sesion_caja_id', $sesion->id)->count())->toBe(0);
    expect(app(CajaService::class)->calcularEfectivoEsperado($sesion))->toBe(0);
});

it('una venta en EFECTIVO exacto registra COBRO_EFECTIVO sin cambio', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user);

    $item = cajaItem(999.99);
    $this->session(['pos.cart' => [$item->id], 'pos.cliente_id' => cajaCliente()->id]);

    $this->actingAs($user)
        ->post(route('pos.checkout'), array_merge([
            'items' => [$item->id],
        ], pagosEfectivo(999.99)))
        ->assertSessionHasNoErrors();

    $pago = PagoVenta::first();
    expect($pago->metodo)->toBe(PagoVenta::METODO_EFECTIVO);
    expect($pago->monto_aplicado)->toBe('999.99');
    expect($pago->efectivo_recibido)->toBe('999.99');
    expect($pago->cambio_entregado)->toBe('0.00');

    $cobro = MovimientoCaja::where('sesion_caja_id', $sesion->id)->first();
    expect($cobro->tipo)->toBe(MovimientoCaja::TIPO_COBRO_EFECTIVO);
    expect($cobro->monto)->toBe('999.99');
    expect(MovimientoCaja::where('sesion_caja_id', $sesion->id)->count())->toBe(1);
});

it('una venta EFECTIVO con billete mayor registra COBRO + CAMBIO_ENTREGADO', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user);

    $item = cajaItem(999.99);
    $this->session(['pos.cart' => [$item->id], 'pos.cliente_id' => cajaCliente()->id]);

    $this->actingAs($user)
        ->post(route('pos.checkout'), array_merge([
            'items' => [$item->id],
        ], pagosEfectivo(999.99, 1000.0)))
        ->assertSessionHasNoErrors();

    $pago = PagoVenta::first();
    expect($pago->monto_aplicado)->toBe('999.99');
    expect($pago->efectivo_recibido)->toBe('1000.00');
    expect($pago->cambio_entregado)->toBe('0.01');

    $tipos = MovimientoCaja::where('sesion_caja_id', $sesion->id)->orderBy('id')->pluck('tipo');
    expect($tipos)->toHaveCount(2);
    expect($tipos->first())->toBe(MovimientoCaja::TIPO_COBRO_EFECTIVO);
    expect($tipos->last())->toBe(MovimientoCaja::TIPO_CAMBIO_ENTREGADO);
});

it('un pago combinado EFECTIVO + TARJETA queda MIXTO con dos pagos', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user);

    $item = cajaItem(999.99);
    $this->session(['pos.cart' => [$item->id], 'pos.cliente_id' => cajaCliente()->id]);

    $this->actingAs($user)
        ->post(route('pos.checkout'), array_merge([
            'items' => [$item->id],
        ], pagosMixtos(999.99, 400.0, PagoVenta::METODO_TARJETA, 599.99)))
        ->assertSessionHasNoErrors();

    $venta = Venta::first();
    expect($venta->forma_pago)->toBe('MIXTO');
    expect($venta->pagos->count())->toBe(2);
    expect($venta->pagos->pluck('metodo')->sort()->values()->all())
        ->toBe([PagoVenta::METODO_EFECTIVO, PagoVenta::METODO_TARJETA]);

    $efectivo = $venta->pagos->first(fn ($p) => $p->metodo === PagoVenta::METODO_EFECTIVO);
    expect($efectivo->monto_aplicado)->toBe('400.00');
    expect($efectivo->cambio_entregado)->toBe('0.00');

    $cobro = MovimientoCaja::where('sesion_caja_id', $sesion->id)->first();
    expect($cobro->tipo)->toBe(MovimientoCaja::TIPO_COBRO_EFECTIVO);
    expect($cobro->monto)->toBe('400.00');
    expect(MovimientoCaja::where('sesion_caja_id', $sesion->id)->count())->toBe(1);
});

it('rechaza la venta sin sesión de caja abierta', function () {
    $user = cajaAdmin();
    $item = cajaItem(100.0);
    $this->session(['pos.cart' => [$item->id], 'pos.cliente_id' => cajaCliente()->id]);

    $this->actingAs($user)
        ->post(route('pos.checkout'), array_merge([
            'items' => [$item->id],
        ], pagosEfectivo(100.0)))
        ->assertSessionHasErrors('caja');

    expect(Venta::count())->toBe(0);
});

it('el efectivo esperado es el saldo físico del cajón (no la suma de ventas)', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 1000.50);

    $v1 = ventaCaja($user, 300.25);
    $totalV1 = Money::aCentavos($v1->total);
    cobrarCaja($v1, $sesion, $user, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '300.25',
        'efectivo_recibido' => '350.25',
        'referencia' => null,
    ]], $totalV1);

    $v2 = ventaCaja($user, 200.0);
    $totalV2 = Money::aCentavos($v2->total);
    cobrarCaja($v2, $sesion, $user, [[
        'metodo' => PagoVenta::METODO_TARJETA,
        'monto_aplicado' => '200.00',
        'referencia' => 'TRX-AB-1',
    ]], $totalV2);

    $v3 = ventaCaja($user, 500.0);
    $totalV3 = Money::aCentavos($v3->total);
    cobrarCaja($v3, $sesion, $user, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '200.00',
        'efectivo_recibido' => '200.00',
        'referencia' => null,
    ], [
        'metodo' => PagoVenta::METODO_TARJETA,
        'monto_aplicado' => '300.00',
        'referencia' => 'TRX-AB-2',
    ]], $totalV3);

    app(CajaService::class)->registrarEntradaManual($sesion, $user, 7525, 'Cambio de mayor', 'REF-1');
    app(CajaService::class)->registrarRetiro($sesion, $user, 50000, 'Depósito a banco', 'REF-2');

    // Reembolso EFECTIVO por cancelación de una venta ACTIVA (vía HTTP para
    // mantener coherencia con Auth y la sesión del operador).
    $vCancel = ventaVendidaCaja($user, [200.0]);
    $this->actingAs($user)
        ->post(route('ventas.cancelar.store', $vCancel), [
            'motivo' => 'Cancelación con reembolso en efectivo.',
            'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        ])
        ->assertSessionHasNoErrors();

    // 1000.50 + 350.25 - 50.00 + 200.00 + 75.25 - 500.00 - 200.00 = 876.00
    expect(app(CajaService::class)->calcularEfectivoEsperado($sesion))->toBe(87600);

    $reembolso = $sesion->movimientos()->where('tipo', MovimientoCaja::TIPO_REEMBOLSO_EFECTIVO)->first();
    expect($reembolso)->not->toBeNull();
    expect($reembolso->monto)->toBe('200.00');
});

it('registra la entrada manual con concepto y referencia exactos', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user);

    $mov = app(CajaService::class)->registrarEntradaManual($sesion, $user, 1250, 'Cambio por menor', 'REF-X');

    expect($mov->tipo)->toBe(MovimientoCaja::TIPO_ENTRADA_MANUAL);
    expect($mov->monto)->toBe('12.50');
    expect($mov->concepto)->toBe('Cambio por menor');
    expect($mov->referencia)->toBe('REF-X');
    expect(app(CajaService::class)->calcularEfectivoEsperado($sesion))->toBe(1250);
});

it('rechaza un retiro que supera el efectivo esperado', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 100.0);

    expect(fn () => app(CajaService::class)->registrarRetiro($sesion, $user, 20000, 'Retiro excesivo'))
        ->toThrow(DomainException::class, 'supera el efectivo esperado');
});

/**
 * =================
 * ARQUEO CIEGO Y CIERRE INMUTABLE
 * =================
 */
it('cierra la sesión con arqueo ciego, diferencia cero y queda inmutable', function () {
    $user = cajaAdmin();
    $open = app(CajaService::class)->abrirSesion(cajaFisica(true, $user), $user, 10000);

    $venta = ventaCaja($user, 50.0);
    cobrarCaja($venta, $open, $user, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '50.00',
        'efectivo_recibido' => '50.00',
        'referencia' => null,
    ]], 5000);

    // Esperado: 100.00 + 50.00 = 150.00 → denominaciones 100 + 50.
    $cerrada = app(CajaService::class)->cerrarSesion($open, $user, ['100' => 1, '50' => 1], 15000, null);

    expect($cerrada->estado)->toBe(SesionCaja::ESTADO_CERRADA);
    expect($cerrada->user_id_cierre)->toBe($user->id);
    expect($cerrada->efectivo_esperado)->toBe('150.00');
    expect($cerrada->efectivo_contado)->toBe('150.00');
    expect($cerrada->diferencia)->toBe('0.00');

    $arqueo = $cerrada->arqueos()->first();
    expect($arqueo->efectivo_contado)->toBe('150.00');
    expect($arqueo->denominaciones->count())->toBe(2);

    // Inmutable: no se puede cerrar dos veces ni cobrar sobre una sesión cerrada.
    expect(fn () => app(CajaService::class)->cerrarSesion($cerrada, $user, ['100' => 1, '50' => 1], 15000, null))
        ->toThrow(DomainException::class, 'no puede cerrarse dos veces');
    expect(fn () => cobrarCaja(ventaCaja($user, 10.0), $cerrada, $user, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '10.00',
        'efectivo_recibido' => '10.00',
        'referencia' => null,
    ]], 1000))
        ->toThrow(DomainException::class, 'ya fue cerrada');
});

it('una diferencia de caja exige la observación obligatoria', function () {
    $user = cajaAdmin();
    $open = app(CajaService::class)->abrirSesion(cajaFisica(true, $user), $user, 10000);

    $venta = ventaCaja($user, 50.0);
    cobrarCaja($venta, $open, $user, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '50.00',
        'efectivo_recibido' => '50.00',
        'referencia' => null,
    ]], 5000);

    // Contado 155.00 vs esperado 150.00 → diferencia 5.00 sin observación → rechazo.
    expect(fn () => app(CajaService::class)->cerrarSesion($open, $user, ['100' => 1, '50' => 1, '5' => 1], 15500, null))
        ->toThrow(DomainException::class, 'observación obligatoria');

    // Con observación sí cierra y persiste la diferencia.
    $cerrada = app(CajaService::class)->cerrarSesion($open, $user, ['100' => 1, '50' => 1, '5' => 1], 15500, 'Sobrante de 5.00 por redondeo');
    expect($cerrada->estado)->toBe(SesionCaja::ESTADO_CERRADA);
    expect($cerrada->diferencia)->toBe('5.00');
});

it('el formulario de cierre oculta el efectivo esperado (arqueo ciego)', function () {
    $user = cajaAdmin();
    $open = app(CajaService::class)->abrirSesion(cajaFisica(true, $user), $user, 10000);

    $venta = ventaCaja($user, 50.0);
    cobrarCaja($venta, $open, $user, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '50.00',
        'efectivo_recibido' => '50.00',
        'referencia' => null,
    ]], 5000);

    $response = $this->actingAs($user)->get(route('cajas.cerrar', $open));

    $response->assertOk();
    $response->assertDontSee('150.00');
    $response->assertDontSee('Efectivo esperado');
});

/**
 * =================
 * OPERACIÓN HTTP, VISIBILIDAD Y POSTVENTA EFECTIVO
 * =================
 */
it('solo el operador de la sesión puede registrar entradas y retiros', function () {
    $operador = cajaRegistrador();
    $ajeno = cajaRegistrador();
    $sesion = openCajaFor($operador);

    // El ajeno no puede operar la sesión del otro.
    $this->actingAs($ajeno)
        ->post(route('cajas.entrada', $sesion), ['monto' => '10.00', 'concepto' => 'Entrada ajena'])
        ->assertForbidden();

    // El propio sí.
    $this->actingAs($operador)
        ->post(route('cajas.entrada', $sesion), ['monto' => '10.00', 'concepto' => 'Entrada legítima'])
        ->assertSessionHasNoErrors();

    // Retiro válido.
    $this->actingAs($operador)
        ->post(route('cajas.retiro', $sesion), ['monto' => '5.00', 'motivo' => 'Gasto menor'])
        ->assertSessionHasNoErrors();

    expect(app(CajaService::class)->calcularEfectivoEsperado($sesion))->toBe(500);
});

it('rechaza en HTTP un retiro mayor al efectivo esperado', function () {
    $user = cajaRegistrador();
    $sesion = openCajaFor($user, 10.0);

    $this->actingAs($user)
        ->post(route('cajas.retiro', $sesion), ['monto' => '50.00', 'motivo' => 'Retiro imposible'])
        ->assertSessionHasErrors('motivo');
});

it('no se puede operar una sesión que ya fue cerrada', function () {
    $user = cajaRegistrador();
    $open = app(CajaService::class)->abrirSesion(cajaFisica(true, $user), $user, 1000);
    app(CajaService::class)->cerrarSesion($open, $user, ['10' => 1], 1000, null);

    $this->actingAs($user)
        ->post(route('cajas.entrada', $open), ['monto' => '1.00', 'concepto' => 'Después del cierre'])
        ->assertStatus(409);
});

it('el index muestra solo las sesiones propias salvo cajas.ver_todas', function () {
    $operador = cajaOperador();
    $admin = cajaAdmin();
    $sesionA = openCajaFor($operador);
    $sesionB = openCajaFor($admin);

    $this->actingAs($operador)->get(route('cajas.index'))
        ->assertOk()
        ->assertSee($sesionA->folio)
        ->assertDontSee($sesionB->folio);

    $this->actingAs($admin)->get(route('cajas.index'))
        ->assertOk()
        ->assertSee($sesionA->folio)
        ->assertSee($sesionB->folio);
});

it('un ajeno no puede ver los movimientos de otra sesión sin cajas.ver_todas', function () {
    $operador = cajaOperador();
    $ajeno = cajaOperador();
    $sesion = openCajaFor($operador);

    $this->actingAs($ajeno)->get(route('cajas.movimientos', $sesion))->assertForbidden();
    $this->actingAs($operador)->get(route('cajas.movimientos', $sesion))->assertOk();
});

it('el reembolso EFECTIVO exige una sesión de caja abierta', function () {
    $user = cajaAdmin();
    $venta = ventaVendidaCaja($user, [100.0]);

    $this->actingAs($user)
        ->post(route('ventas.cancelar.store', $venta), [
            'motivo' => 'Cancelación sin caja abierta.',
            'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        ])
        ->assertSessionHasErrors('reembolso');

    expect(DocumentoPostventa::count())->toBe(0);
    expect(MovimientoCaja::count())->toBe(0);
});

it('un reembolso EFECTIVO con sesión deja REEMBOLSO_EFECTIVO en el cajón', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 1000.0);
    $venta = ventaVendidaCaja($user, [250.5, 250.5]);

    $this->actingAs($user)
        ->post(route('ventas.cancelar.store', $venta), [
            'motivo' => 'Cancelación con reembolso en efectivo.',
            'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        ])
        ->assertSessionHasNoErrors();

    $reembolso = MovimientoCaja::where('sesion_caja_id', $sesion->id)
        ->where('tipo', MovimientoCaja::TIPO_REEMBOLSO_EFECTIVO)
        ->first();

    expect($reembolso)->not->toBeNull();
    expect($reembolso->monto)->toBe('501.00');
    expect(app(CajaService::class)->calcularEfectivoEsperado($sesion))->toBe(49900);
});

it('el cierre HTTP persiste estado, esperado y diferencia con observación', function () {
    $user = cajaAdmin();
    $open = app(CajaService::class)->abrirSesion(cajaFisica(true, $user), $user, 10000);

    $venta = ventaCaja($user, 50.0);
    cobrarCaja($venta, $open, $user, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '50.00',
        'efectivo_recibido' => '50.00',
        'referencia' => null,
    ]], 5000);

    $this->actingAs($user)
        ->post(route('cajas.cerrar.store', $open), [
            'denominaciones' => ['100' => 1, '50' => 1, '5' => 1],
            'observaciones_cierre' => 'Sobrante de 5.00.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $cerrada = SesionCaja::find($open->id);
    expect($cerrada->estado)->toBe(SesionCaja::ESTADO_CERRADA);
    expect($cerrada->efectivo_esperado)->toBe('150.00');
    expect($cerrada->efectivo_contado)->toBe('155.00');
    expect($cerrada->diferencia)->toBe('5.00');
    expect($cerrada->observaciones_cierre)->toBe('Sobrante de 5.00.');
});

it('el dashboard muestra la sesión de caja abierta del operador', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 250.0);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee($sesion->folio)
        ->assertSee($sesion->caja->nombre);
});

it('el cierre HTTP sin observación ante diferencia devuelve error', function () {
    $user = cajaAdmin();
    $open = app(CajaService::class)->abrirSesion(cajaFisica(true, $user), $user, 10000);

    $venta = ventaCaja($user, 50.0);
    cobrarCaja($venta, $open, $user, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '50.00',
        'efectivo_recibido' => '50.00',
        'referencia' => null,
    ]], 5000);

    $this->actingAs($user)
        ->post(route('cajas.cerrar.store', $open), [
            'denominaciones' => ['100' => 1, '50' => 1, '5' => 1],
            'observaciones_cierre' => '',
        ])
        ->assertSessionHasErrors('observaciones_cierre');

    expect($open->fresh()->estado)->toBe(SesionCaja::ESTADO_ABIERTA);
});

/**
 * =================
 * HARDENING B14: AJUSTES ADMIN, PERMISOS SEGUROS, CONSTRAINT DB, CORTE
 * =================
 */
it('registra un AJUSTE de ENTRADA y suma al efectivo esperado', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 1000.0);

    $mov = app(CajaService::class)->registrarAjuste($sesion, $user, 5000, MovimientoCaja::DIR_ENTRADA, 'Sobrante detectado en auditoría', 'REF-AJ-1');

    expect($mov->tipo)->toBe(MovimientoCaja::TIPO_AJUSTE);
    expect($mov->direccion)->toBe(MovimientoCaja::DIR_ENTRADA);
    expect($mov->monto)->toBe('50.00');
    expect($mov->concepto)->toBe('Sobrante detectado en auditoría');
    expect(app(CajaService::class)->calcularEfectivoEsperado($sesion))->toBe(105000);
});

it('registra un AJUSTE de SALIDA y resta del efectivo esperado', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 1000.0);

    app(CajaService::class)->registrarAjuste($sesion, $user, 20000, MovimientoCaja::DIR_SALIDA, 'Faltante por redondeo', 'REF-AJ-2');

    expect(app(CajaService::class)->calcularEfectivoEsperado($sesion))->toBe(80000);
});

it('rechaza un AJUSTE de SALIDA que deja el efectivo esperado negativo', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 50.0);

    expect(fn () => app(CajaService::class)->registrarAjuste($sesion, $user, 6000, MovimientoCaja::DIR_SALIDA, 'Ajuste imposible'))
        ->toThrow(DomainException::class, 'supera el efectivo esperado');
});

it('rechaza un ajuste sobre una sesión cerrada', function () {
    $user = cajaAdmin();
    $open = app(CajaService::class)->abrirSesion(cajaFisica(true, $user), $user, 1000);
    app(CajaService::class)->cerrarSesion($open, $user, ['10' => 1], 1000, null);

    expect(fn () => app(CajaService::class)->registrarAjuste($open->fresh(), $user, 100, MovimientoCaja::DIR_ENTRADA, 'Después del cierre'))
        ->toThrow(DomainException::class, 'está cerrada');
});

it('un ajuste queda inmutable: se persiste con su dirección y concepto exactos', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 1000.0);
    $mov = app(CajaService::class)->registrarAjuste($sesion, $user, 1000, MovimientoCaja::DIR_ENTRADA, 'Ajuste de prueba');

    $this->assertDatabaseHas('movimientos_caja', [
        'id' => $mov->id,
        'tipo' => MovimientoCaja::TIPO_AJUSTE,
        'direccion' => MovimientoCaja::DIR_ENTRADA,
        'monto' => '10.00',
        'concepto' => 'Ajuste de prueba',
    ]);

    expect(MovimientoCaja::where('id', $mov->id)->count())->toBe(1);
});

it('ventas NO puede registrar entrada, retiro ni ajuste (403)', function () {
    $user = cajaOperador();
    $sesion = openCajaFor($user, 100.0);

    $this->actingAs($user)
        ->post(route('cajas.entrada', $sesion), ['monto' => '1.00', 'concepto' => 'x'])
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('cajas.retiro', $sesion), ['monto' => '1.00', 'motivo' => 'x'])
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('cajas.ajuste', $sesion), ['monto' => '1.00', 'direccion' => MovimientoCaja::DIR_ENTRADA, 'motivo' => 'x'])
        ->assertForbidden();

    expect(MovimientoCaja::count())->toBe(0);
});

it('admin registra entrada, retiro y ajuste por HTTP', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 100.0);

    $this->actingAs($user)
        ->post(route('cajas.entrada', $sesion), ['monto' => '10.00', 'concepto' => 'Cambio por menor'])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->post(route('cajas.retiro', $sesion), ['monto' => '5.00', 'motivo' => 'Gasto administrativo'])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->post(route('cajas.ajuste', $sesion), ['monto' => '2.00', 'direccion' => MovimientoCaja::DIR_SALIDA, 'motivo' => 'Redondeo'])
        ->assertSessionHasNoErrors();

    $tipos = $sesion->movimientos->pluck('tipo')->sort()->values()->all();
    expect($tipos)->toContain(MovimientoCaja::TIPO_ENTRADA_MANUAL);
    expect($tipos)->toContain(MovimientoCaja::TIPO_RETIRO);
    expect($tipos)->toContain(MovimientoCaja::TIPO_AJUSTE);

    // 100.00 + 10.00 - 5.00 - 2.00 = 103.00
    expect(app(CajaService::class)->calcularEfectivoEsperado($sesion))->toBe(10300);
});

it('valida dirección y motivo obligatorio en el ajuste HTTP', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 100.0);

    $this->actingAs($user)
        ->post(route('cajas.ajuste', $sesion), ['monto' => '10.00', 'direccion' => 'X', 'motivo' => ''])
        ->assertSessionHasErrors(['direccion', 'motivo']);
});

it('el seeder no duplica la Caja Principal (idempotente por código)', function () {
    $this->seed(\Database\Seeders\RolesAndAdminSeeder::class);
    $this->seed(\Database\Seeders\RolesAndAdminSeeder::class);

    $cajas = Caja::query()->orderBy('id')->get();

    // Antes del seeder ya existía la creada en el primer run (RefreshDatabase → seed en beforeEach? no): contamos.
    $principales = $cajas->filter(fn ($c) => $c->codigo === 'CAJ-000001');
    expect($principales->count())->toBe(1);
    expect($principales->first()->nombre)->toBe('Caja Principal');
    // B14.3.1 FIX 3: seed inicial de la caja principal es INACTIVA (no hay
    // operador aún que asignar a una caja activa).
    expect($principales->first()->activa)->toBeFalse();
});

it('produce el corte como PDF y contiene el folio de la sesión', function () {
    $user = cajaAdmin();
    $open = app(CajaService::class)->abrirSesion(cajaFisica(true, $user), $user, 10000);
    $cerrada = app(CajaService::class)->cerrarSesion($open, $user, ['100' => 1], 10000, null);

    $response = $this->actingAs($user)->get(route('cajas.corte.pdf', $cerrada));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    $resp = $response->baseResponse;
    expect($resp->headers->get('Content-Disposition'))->toContain('corte_caja_'.$cerrada->folio.'.pdf');
});

it('produce el corte como XLSX y contiene el folio de la sesión', function () {
    $user = cajaAdmin();
    $open = app(CajaService::class)->abrirSesion(cajaFisica(true, $user), $user, 10000);
    $cerrada = app(CajaService::class)->cerrarSesion($open, $user, ['100' => 1], 10000, null);

    $response = $this->actingAs($user)->get(route('cajas.corte.xlsx', $cerrada));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml');
    $resp = $response->baseResponse;
    expect($resp->headers->get('Content-Disposition'))->toContain('corte_caja_'.$cerrada->folio.'.xlsx');
});

it('un ajeno sin cajas.ver_todas no descarga el corte de otra sesión', function () {
    $operador = cajaRegistrador();
    $ajeno = cajaRegistrador();
    $open = openCajaFor($operador, 1000);
    app(CajaService::class)->cerrarSesion($open, $operador, ['1000' => 1], 100000, null);

    $this->actingAs($ajeno)->get(route('cajas.corte.pdf', $open))->assertForbidden();
    $this->actingAs($ajeno)->get(route('cajas.corte.xlsx', $open))->assertForbidden();
});

it('los pagos POS en EFECTIVO cumplen la restricción DB de recibido/cambio', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 0.0);

    // EFECTIVO exacto: recibido >= aplicado y cambio = recibido - aplicado.
    $pago = PagoVenta::create([
        'venta_id' => ventaCaja($user, 250.0)->id,
        'sesion_caja_id' => $sesion->id,
        'user_id' => $user->id,
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '250.00',
        'efectivo_recibido' => '250.00',
        'cambio_entregado' => '0.00',
        'referencia' => null,
        'origen' => PagoVenta::ORIGEN_POS,
    ]);
    expect($pago->id)->not->toBeNull();
});

it('rechaza en DB un cambio operacional incoherente (recibido - aplicado ≠ cambio)', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 0.0);

    expect(fn () => PagoVenta::create([
        'venta_id' => ventaCaja($user, 100.0)->id,
        'sesion_caja_id' => $sesion->id,
        'user_id' => $user->id,
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '100.00',
        'efectivo_recibido' => '150.00',
        'cambio_entregado' => '10.00',
        'referencia' => null,
        'origen' => PagoVenta::ORIGEN_POS,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('rechaza en DB un pago TARJETA operacional con efectivo tocado', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 0.0);

    expect(fn () => PagoVenta::create([
        'venta_id' => ventaCaja($user, 100.0)->id,
        'sesion_caja_id' => $sesion->id,
        'user_id' => $user->id,
        'metodo' => PagoVenta::METODO_TARJETA,
        'monto_aplicado' => '100.00',
        'efectivo_recibido' => '100.00',
        'referencia' => 'TRX-1',
        'origen' => PagoVenta::ORIGEN_POS,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('los movimientos de caja se crean con dirección coherente a su tipo', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 0.0);

    $mov = MovimientoCaja::create([
        'sesion_caja_id' => $sesion->id,
        'user_id' => $user->id,
        'tipo' => MovimientoCaja::TIPO_ENTRADA_MANUAL,
        'direccion' => MovimientoCaja::DIR_ENTRADA,
        'monto' => '10.00',
        'concepto' => 'Entrada coherente',
    ]);
    expect($mov->id)->not->toBeNull();

    $ajuste = MovimientoCaja::create([
        'sesion_caja_id' => $sesion->id,
        'user_id' => $user->id,
        'tipo' => MovimientoCaja::TIPO_AJUSTE,
        'direccion' => MovimientoCaja::DIR_SALIDA,
        'monto' => '5.00',
        'concepto' => 'Ajuste salida',
    ]);
    expect($ajuste->id)->not->toBeNull();
});

it('rechaza en DB un movimiento con tipo y dirección incoherentes', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 0.0);

    expect(fn () => MovimientoCaja::create([
        'sesion_caja_id' => $sesion->id,
        'user_id' => $user->id,
        'tipo' => MovimientoCaja::TIPO_ENTRADA_MANUAL,
        'direccion' => MovimientoCaja::DIR_SALIDA,
        'monto' => '10.00',
        'concepto' => 'Incoherente',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

/**
 * ============================================================
 * B14.1 — REIMPRESION E HISTORIAL DE CORTES
 * ============================================================
 */
it('B14.1 permite consultar e imprimir en HTML una sesión cerrada', function () {
    $user = cajaAdmin();
    $open = openCajaFor($user, 1000.0);

    $cerrada = app(CajaService::class)->cerrarSesion(
        $open,
        $user,
        ['1000' => 1],
        100000,
        null,
    );

    $web = $this->actingAs($user)
        ->get(route('cajas.corte', $cerrada));

    $web->assertOk()
        ->assertSee('Corte de caja')
        ->assertSee($cerrada->folio)
        ->assertSee('Imprimir')
        ->assertSee('PDF')
        ->assertSee('XLSX');

    $imprimir = $this->actingAs($user)
        ->get(route('cajas.corte.imprimir', $cerrada));

    $imprimir->assertOk()
        ->assertSee($cerrada->folio)
        ->assertSee('window.print()', false);
});

it('B14.1 bloquea WEB imprimir PDF y XLSX mientras la sesión siga abierta', function () {
    $user = cajaAdmin();
    $open = openCajaFor($user, 1000.0);

    foreach ([
        'cajas.corte',
        'cajas.corte.imprimir',
        'cajas.corte.pdf',
        'cajas.corte.xlsx',
    ] as $routeName) {
        $this->actingAs($user)
            ->get(route($routeName, $open))
            ->assertStatus(409);
    }
});

it('B14.1 un operador ajeno sin cajas.ver_todas no consulta ni imprime otro corte', function () {
    $operador = cajaAdmin();
    $ajeno = cajaOperador();

    $open = openCajaFor($operador, 1000.0);

    $cerrada = app(CajaService::class)->cerrarSesion(
        $open,
        $operador,
        ['1000' => 1],
        100000,
        null,
    );

    $this->actingAs($ajeno)
        ->get(route('cajas.corte', $cerrada))
        ->assertForbidden();

    $this->actingAs($ajeno)
        ->get(route('cajas.corte.imprimir', $cerrada))
        ->assertForbidden();
});

it('B14.1 auditor con cajas.ver y cajas.ver_todas puede reimprimir sin cajas.movimientos', function () {
    $operador = cajaAdmin();

    $open = openCajaFor($operador, 1000.0);

    $cerrada = app(CajaService::class)->cerrarSesion(
        $open,
        $operador,
        ['1000' => 1],
        100000,
        null,
    );

    $auditor = User::factory()->create();
    $auditor->givePermissionTo([
        'cajas.ver',
        'cajas.ver_todas',
    ]);

    expect($auditor->can('cajas.movimientos'))->toBeFalse();

    $index = $this->actingAs($auditor)
        ->get(route('cajas.index'));

    $index->assertOk()
        ->assertSee($cerrada->folio)
        ->assertSee('Ver corte')
        ->assertSee('Imprimir')
        ->assertSee('PDF')
        ->assertSee('XLSX');

    $this->actingAs($auditor)
        ->get(route('cajas.corte', $cerrada))
        ->assertOk()
        ->assertSee($cerrada->folio);

    $this->actingAs($auditor)
        ->get(route('cajas.corte.imprimir', $cerrada))
        ->assertOk()
        ->assertSee('window.print()', false);
});

/**
 * ============================================================
 * B14.3 — CORTE CIEGO REAL (sesión ABIERTA no filtra el esperado)
 * ============================================================
 */
it('B14.3 el propietario de una sesión ABIERTA no ve el efectivo esperado en movimientos', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 1000.0);

    $venta = ventaCaja($user, 350.25);
    cobrarCaja($venta, $sesion, $user, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '350.25',
        'efectivo_recibido' => '350.25',
        'referencia' => null,
    ]], Money::aCentavos($venta->total));

    expect(app(CajaService::class)->calcularEfectivoEsperado($sesion))->toBe(135025);

    $this->actingAs($user)
        ->get(route('cajas.movimientos', $sesion))
        ->assertOk()
        ->assertDontSee('Efectivo esperado')
        ->assertDontSee('1,350.25')
        ->assertDontSee('1350.25');
});

it('B14.3 una sesión ABIERTA no muestra los totales agregados Entradas/Salidas', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 100.0);

    $venta = ventaCaja($user, 60.0);
    cobrarCaja($venta, $sesion, $user, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '60.00',
        'efectivo_recibido' => '100.00',
        'referencia' => null,
    ]], Money::aCentavos($venta->total));

    $this->actingAs($user)
        ->get(route('cajas.movimientos', $sesion))
        ->assertOk()
        ->assertDontSee('Entradas:')
        ->assertDontSee('Salidas:')
        ->assertSee('COBRO_EFECTIVO')
        ->assertSee('CAMBIO_ENTREGADO');
});

it('B14.3 una sesión ABIERTA no calcula ni entrega agregados entradas/salidas a la vista', function () {
    $user = cajaAdmin();
    $sesion = openCajaFor($user, 100.0);

    $venta = ventaCaja($user, 60.0);
    cobrarCaja($venta, $sesion, $user, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '60.00',
        'efectivo_recibido' => '100.00',
        'referencia' => null,
    ]], Money::aCentavos($venta->total));

    $response = $this->actingAs($user)->get(route('cajas.movimientos', $sesion));

    $response->assertOk();
    $response->assertViewHas('entradas', null);
    $response->assertViewHas('salidas', null);
    $response->assertViewHas('sesion');

    // No se reintroduce el esperado.
    $response->assertViewMissing('esperado');
    // El libro detallado continúa en la vista.
    $response->assertSee('COBRO_EFECTIVO');
});

it('B14.3 una sesión CERRADA sí calcula los agregados entradas/salidas', function () {
    $user = cajaAdmin();
    $open = app(CajaService::class)->abrirSesion(cajaFisica(true, $user), $user, 10000);

    $venta = ventaCaja($user, 50.0);
    cobrarCaja($venta, $open, $user, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '50.00',
        'efectivo_recibido' => '100.00',
        'referencia' => null,
    ]], 5000);

    app(CajaService::class)->registrarEntradaManual($open, $user, 2500, 'Cambio por menor');

    // Esperado: 100.00 (fondo) + 100.00 (cobro bruto) - 50.00 (cambio) + 25.00 (manual) = 175.00.
    $cerrada = app(CajaService::class)->cerrarSesion($open, $user, ['100' => 1, '50' => 1, '20' => 1, '5' => 1], 17500, null);

    $response = $this->actingAs($user)->get(route('cajas.movimientos', $cerrada));

    // Entradas (cobro 100.00 + manual 25.00 = 125.00); salidas excluye CAMBIO_ENTREGADO → 0.
    $response->assertOk()->assertViewHas('entradas', 12500);
    $response->assertViewHas('salidas', 0);
    $response->assertSee('Entradas:');
    $response->assertSee('Salidas:');
});

it('B14.3 una sesión CERRADA sí muestra esperado, contado y diferencia', function () {
    $user = cajaAdmin();
    $open = app(CajaService::class)->abrirSesion(cajaFisica(true, $user), $user, 10000);

    $venta = ventaCaja($user, 50.0);
    cobrarCaja($venta, $open, $user, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '50.00',
        'efectivo_recibido' => '50.00',
        'referencia' => null,
    ]], 5000);

    $cerrada = app(CajaService::class)->cerrarSesion($open, $user, ['100' => 1, '50' => 1], 15000, null);

    $this->actingAs($user)
        ->get(route('cajas.movimientos', $cerrada))
        ->assertOk()
        ->assertSee('Resultado del corte')
        ->assertSee('150.00')
        ->assertSee('Esperado')
        ->assertSee('Contado (arqueo)')
        ->assertSee('Diferencia');
});

it('B14.3 Admin con ver_todas no provoca fuga al consultar la sesión ABIERTA ajena', function () {
    $operador = cajaRegistrador();
    $sesion = openCajaFor($operador, 500.0);

    $venta = ventaCaja($operador, 123.45);
    cobrarCaja($venta, $sesion, $operador, [[
        'metodo' => PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => '123.45',
        'efectivo_recibido' => '123.45',
        'referencia' => null,
    ]], Money::aCentavos($venta->total));

    expect(app(CajaService::class)->calcularEfectivoEsperado($sesion))->toBe(62345);

    $admin = cajaAdmin();

    $this->actingAs($admin)
        ->get(route('cajas.movimientos', $sesion))
        ->assertOk()
        ->assertDontSee('Efectivo esperado')
        ->assertDontSee('623.45');
});

it('B14.3 Auditor sin cajas.movimientos continúa sin acceso a movimientos', function () {
    $operador = cajaAdmin();
    $sesion = openCajaFor($operador, 0.0);

    $auditor = User::factory()->create();
    $auditor->givePermissionTo(['cajas.ver', 'cajas.ver_todas']);

    expect($auditor->can('cajas.movimientos'))->toBeFalse();

    $this->actingAs($auditor)
        ->get(route('cajas.movimientos', $sesion))
        ->assertForbidden();
});

/**
 * ============================================================
 * B14.3 — MULTICAJA: gestión administrativa del maestro de cajas
 * ============================================================
 */
it('B14.3 admin con cajas.configurar lista las cajas', function () {
    $admin = cajaAdmin();
    $caja = cajaFisica();

    $this->actingAs($admin)
        ->get(route('cajas.gestion'))
        ->assertOk()
        ->assertSee($caja->codigo)
        ->assertSee($caja->nombre)
        ->assertSee('ACTIVA');
});

it('B14.3 un usuario sin cajas.configurar recibe 403 en la gestión de cajas', function () {
    $user = cajaOperador();

    $this->actingAs($user)->get(route('cajas.gestion'))->assertForbidden();
    $this->actingAs($user)->get(route('cajas.gestion.crear'))->assertForbidden();
    $this->actingAs($user)->post(route('cajas.gestion.store'), ['nombre' => 'Caja X'])->assertForbidden();

    $caja = cajaFisica();
    $this->actingAs($user)->get(route('cajas.gestion.editar', $caja))->assertForbidden();
    $this->actingAs($user)->put(route('cajas.gestion.update', $caja), ['nombre' => 'Caja X'])->assertForbidden();
});

it('B14.3 crear cajas produce códigos distintos, secuenciales y nunca manuales', function () {
    $admin = cajaAdmin();
    $op1 = cajaOperador();
    $op2 = cajaOperador();

    $this->actingAs($admin)
        ->post(route('cajas.gestion.store'), [
            'nombre' => 'Caja Norte',
            'descripcion' => 'Primera sucursal',
            'usuario_asignado_id' => $op1->id,
            'activa' => '1',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('cajas.gestion'));

    // Intento de forzar el código: el servidor debe ignorarlo (secuencia).
    $this->actingAs($admin)
        ->post(route('cajas.gestion.store'), [
            'nombre' => 'Caja Hack',
            'codigo' => 'CAJ-999999',
            'usuario_asignado_id' => $op2->id,
            'activa' => '1',
        ])
        ->assertSessionHasNoErrors();

    $codigos = Caja::query()->orderBy('id')->pluck('codigo')->values();

    expect($codigos)->toHaveCount(2);
    expect($codigos[0])->toMatch('/^CAJ-\d{6}$/');
    expect($codigos[1])->toMatch('/^CAJ-\d{6}$/');
    expect($codigos[1])->not->toBe($codigos[0]);
    expect((int) substr($codigos[1], 4))->toBe((int) substr($codigos[0], 4) + 1);

    expect(Caja::where('codigo', 'CAJ-999999')->exists())->toBeFalse();
});

it('B14.3 editar una caja no cambia su código y persiste nombre y descripción', function () {
    $admin = cajaAdmin();
    $op = cajaOperador();
    $caja = cajaFisica(true, $op);
    $codigo = $caja->codigo;

    $this->actingAs($admin)
        ->put(route('cajas.gestion.update', $caja), [
            'nombre' => 'Caja Renombrada',
            'descripcion' => 'Nueva descripción de la caja.',
            'activa' => '1',
            'usuario_asignado_id' => $op->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('cajas.gestion'));

    $fresh = $caja->fresh();
    expect($fresh->codigo)->toBe($codigo);
    expect($fresh->nombre)->toBe('Caja Renombrada');
    expect($fresh->descripcion)->toBe('Nueva descripción de la caja.');
    expect($fresh->activa)->toBeTrue();
});

it('B14.3 se puede desactivar y volver a activar una caja sin sesión abierta', function () {
    $admin = cajaAdmin();
    $op = cajaOperador();
    $caja = cajaFisica(true, $op);

    $this->actingAs($admin)
        ->put(route('cajas.gestion.update', $caja), [
            'nombre' => $caja->nombre,
            'activa' => '0',
            'usuario_asignado_id' => $op->id,
        ])
        ->assertSessionHasNoErrors();

    expect($caja->fresh()->activa)->toBeFalse();

    $this->actingAs($admin)
        ->put(route('cajas.gestion.update', $caja), [
            'nombre' => $caja->nombre,
            'activa' => '1',
            'usuario_asignado_id' => $op->id,
        ])
        ->assertSessionHasNoErrors();

    expect($caja->fresh()->activa)->toBeTrue();
});

it('B14.3 no se puede desactivar una caja con sesión abierta', function () {
    $admin = cajaAdmin();
    $caja = cajaFisica(true, $admin);
    $sesion = app(CajaService::class)->abrirSesion($caja, $admin, 0);

    $this->actingAs($admin)
        ->put(route('cajas.gestion.update', $caja), [
            'nombre' => $caja->nombre,
            'activa' => '0',
            'usuario_asignado_id' => $admin->id,
        ])
        ->assertSessionHasErrors('activa');

    expect($caja->fresh()->activa)->toBeTrue();
    expect($sesion->fresh()->estado)->toBe(SesionCaja::ESTADO_ABIERTA);
});

it('B14.3 una caja inactiva no está disponible al abrir sesión', function () {
    $admin = cajaAdmin();
    $otro = cajaRegistrador();
    $activa = Caja::create(['nombre' => 'Caja Activa Norte', 'activa' => true, 'usuario_asignado_id' => $admin->id]);
    $inactiva = Caja::create(['nombre' => 'Caja Inactiva Sur', 'activa' => false, 'usuario_asignado_id' => $otro->id]);

    $this->actingAs($admin)
        ->get(route('cajas.abrir'))
        ->assertOk()
        ->assertSee($activa->nombre)
        ->assertDontSee($inactiva->nombre);

    expect(fn () => app(CajaService::class)->abrirSesion($inactiva->fresh(), $otro, 0))
        ->toThrow(DomainException::class, 'inactiva');
});

it('B14.3 dos operadores distintos abren dos cajas distintas simultáneamente', function () {
    $a = cajaRegistrador();
    $b = cajaRegistrador();
    $cajaA = cajaFisica(true, $a);
    $cajaB = cajaFisica(true, $b);

    $sesionA = app(CajaService::class)->abrirSesion($cajaA, $a, 1000);
    $sesionB = app(CajaService::class)->abrirSesion($cajaB, $b, 2000);

    expect($sesionA->caja_id)->toBe($cajaA->id);
    expect($sesionB->caja_id)->toBe($cajaB->id);
    expect($sesionA->user_id_apertura)->toBe($a->id);
    expect($sesionB->user_id_apertura)->toBe($b->id);
    expect(SesionCaja::abiertas()->count())->toBe(2);
});

it('B14.3 un operador no asignado no puede abrir una caja', function () {
    $a = cajaRegistrador();
    $b = cajaRegistrador();
    $caja = cajaFisica(true, $a);

    app(CajaService::class)->abrirSesion($caja, $a, 0);

    // $b no está asignado a la caja: el servicio lo rechaza.
    expect(fn () => app(CajaService::class)->abrirSesion($caja, $b, 0))
        ->toThrow(DomainException::class, 'no está asignada a tu usuario');
});

it('B14.3 un operador con su caja abierta no puede abrir otra', function () {
    $user = cajaRegistrador();
    $otro = cajaRegistrador();
    $cajaPropia = cajaFisica(true, $user);
    $cajaAjena = cajaFisica(true, $otro);

    app(CajaService::class)->abrirSesion($cajaPropia, $user, 0);

    // No puede abrir una caja ajena (además ya tiene una sesión abierta).
    expect(fn () => app(CajaService::class)->abrirSesion($cajaAjena, $user, 0))
        ->toThrow(DomainException::class);
});

it('B14.3 el historial de una caja desactivada permanece', function () {
    $user = cajaAdmin();
    $caja = cajaFisica(true, $user);
    $sesion = app(CajaService::class)->abrirSesion($caja, $user, 1000);
    app(CajaService::class)->cerrarSesion($sesion, $user, ['10' => 1], 1000, null);

    $this->actingAs($user)
        ->put(route('cajas.gestion.update', $caja), [
            'nombre' => $caja->nombre,
            'activa' => '0',
            'usuario_asignado_id' => $user->id,
        ])
        ->assertSessionHasNoErrors();

    expect($caja->fresh()->activa)->toBeFalse();
    expect(Caja::find($caja->id)->sesiones()->count())->toBe(1);

    $this->actingAs($user)
        ->get(route('cajas.index'))
        ->assertOk()
        ->assertSee($sesion->folio);
});

it('B14.3 no existe borrado destructivo de cajas ni de su historial', function () {
    $user = cajaAdmin();
    $caja = cajaFisica(true, $user);
    $sesion = app(CajaService::class)->abrirSesion($caja, $user, 1000);
    app(CajaService::class)->cerrarSesion($sesion, $user, ['10' => 1], 1000, null);

    // No hay ruta DELETE para el maestro de cajas: alta/baja vía activa=false.
    $this->actingAs($user)
        ->delete(route('cajas.gestion.update', $caja))
        ->assertStatus(405);

    expect(Caja::count())->toBe(1);
    expect(SesionCaja::count())->toBe(1);
    expect($sesion->fresh()->caja_id)->toBe($caja->id);
});

it('B14.3 el enlace de navegación a administración de cajas solo aparece con cajas.configurar', function () {
    $this->actingAs(cajaAdmin())->get(route('cajas.index'))
        ->assertOk()
        ->assertSee('Administración de cajas');

    $this->actingAs(cajaOperador())->get(route('cajas.index'))
        ->assertOk()
        ->assertSee('Operación de caja')
        ->assertDontSee('Administración de cajas');
});

it('B14.3 la navegación aísla Caja operativa de Administración de cajas en desktop y móvil', function () {
    $admin = cajaAdmin();
    cajaFisica();

    // En index, "Operación de caja" está activo y "Administración de cajas" no.
    $index = $this->actingAs($admin)->get(route('cajas.index'));
    $index->assertOk()->assertSee('Administración de cajas');
    $htmlIndex = $index->getContent();

    preg_match('/<a[^>]*href="[^"]*\/cajas"[^>]*class="([^"]*)"[^>]*>\s*Operación de caja\s*<\/a>/s', $htmlIndex, $cajaIndex);
    preg_match('/<a[^>]*href="[^"]*\/cajas\/gestion"[^>]*class="([^"]*)"[^>]*>\s*Administración de cajas\s*<\/a>/s', $htmlIndex, $gestionIndex);

    expect($cajaIndex[1] ?? '')->toContain('bg-gray-100 font-medium text-gray-900');
    expect($gestionIndex[1] ?? '')->not->toContain('bg-gray-100 font-medium text-gray-900');

    // En la gestión, se invierte: "Administración de cajas" activo y "Operación de caja" NO.
    $gestion = $this->actingAs($admin)->get(route('cajas.gestion'));
    $gestion->assertOk();
    $htmlGestion = $gestion->getContent();

    preg_match('/<a[^>]*href="[^"]*\/cajas"[^>]*class="([^"]*)"[^>]*>\s*Operación de caja\s*<\/a>/s', $htmlGestion, $cajaGest);
    preg_match('/<a[^>]*href="[^"]*\/cajas\/gestion"[^>]*class="([^"]*)"[^>]*>\s*Administración de cajas\s*<\/a>/s', $htmlGestion, $gestionGest);

    expect($cajaGest[1] ?? '')->not->toContain('bg-gray-100 font-medium text-gray-900');
    expect($gestionGest[1] ?? '')->toContain('bg-gray-100 font-medium text-gray-900');
});
