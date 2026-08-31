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

function cajaFisica(bool $activa = true): Caja
{
    return Caja::create([
        'nombre' => 'Caja Principal B14',
        'activa' => $activa,
        'descripcion' => 'Caja de prueba.',
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
    $caja = cajaFisica();

    $sesion = app(CajaService::class)->abrirSesion($caja, $user, Money::aCentavos('1500.25'));

    expect($sesion->folio)->toMatch('/^COR-\d{6}$/');
    expect($sesion->estado)->toBe(SesionCaja::ESTADO_ABIERTA);
    expect($sesion->caja_id)->toBe($caja->id);
    expect($sesion->user_id_apertura)->toBe($user->id);
    expect($sesion->fondo_inicial)->toBe('1500.25');
});

it('una caja física no admite dos sesiones ABIERTAS simultáneas', function () {
    $user = cajaAdmin();
    $caja = cajaFisica();
    app(CajaService::class)->abrirSesion($caja, $user, 0);

    expect(fn () => app(CajaService::class)->abrirSesion($caja, $user, 0))
        ->toThrow(DomainException::class, 'ya tiene una sesión abierta');
});

it('un operador no puede tener dos sesiones ABIERTAS', function () {
    $user = cajaAdmin();
    app(CajaService::class)->abrirSesion(cajaFisica(), $user, 0);

    expect(fn () => app(CajaService::class)->abrirSesion(cajaFisica(), $user, 0))
        ->toThrow(DomainException::class, 'Ya tienes una sesión de caja abierta');
});

it('rechaza abrir una caja inactiva', function () {
    $user = cajaAdmin();

    expect(fn () => app(CajaService::class)->abrirSesion(cajaFisica(false), $user, 0))
        ->toThrow(DomainException::class, 'inactiva');
});

it('el formulario y POST de apertura exigen el permiso cajas.abrir', function () {
    $user = cajaOperador();
    $user->revokePermissionTo('cajas.abrir');

    $this->actingAs($user)->get(route('cajas.abrir'))->assertForbidden();
    $this->actingAs($user)->post(route('cajas.abrir.store'), ['caja_id' => 1, 'fondo_inicial' => '0.00'])->assertForbidden();
});

it('abre por HTTP, genera folio COR y redirige a los movimientos', function () {
    $user = cajaAdmin();
    $caja = cajaFisica();

    $this->actingAs($user)
        ->post(route('cajas.abrir.store'), [
            'caja_id' => $caja->id,
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
    $caja = cajaFisica();

    $this->actingAs($user)
        ->post(route('cajas.abrir.store'), [
            'caja_id' => $caja->id,
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
    $open = app(CajaService::class)->abrirSesion(cajaFisica(), $user, 10000);

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
    $open = app(CajaService::class)->abrirSesion(cajaFisica(), $user, 10000);

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
    $open = app(CajaService::class)->abrirSesion(cajaFisica(), $user, 10000);

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
    $open = app(CajaService::class)->abrirSesion(cajaFisica(), $user, 1000);
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
    $open = app(CajaService::class)->abrirSesion(cajaFisica(), $user, 10000);

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
    $open = app(CajaService::class)->abrirSesion(cajaFisica(), $user, 10000);

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
    $open = app(CajaService::class)->abrirSesion(cajaFisica(), $user, 1000);
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
    expect($principales->first()->activa)->toBeTrue();
});

it('produce el corte como PDF y contiene el folio de la sesión', function () {
    $user = cajaAdmin();
    $open = app(CajaService::class)->abrirSesion(cajaFisica(), $user, 10000);
    $cerrada = app(CajaService::class)->cerrarSesion($open, $user, ['100' => 1], 10000, null);

    $response = $this->actingAs($user)->get(route('cajas.corte.pdf', $cerrada));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    $resp = $response->baseResponse;
    expect($resp->headers->get('Content-Disposition'))->toContain('corte_caja_'.$cerrada->folio.'.pdf');
});

it('produce el corte como XLSX y contiene el folio de la sesión', function () {
    $user = cajaAdmin();
    $open = app(CajaService::class)->abrirSesion(cajaFisica(), $user, 10000);
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
