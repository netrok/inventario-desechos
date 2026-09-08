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
use App\Support\CxCAcceso;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['cxc.ver', 'cxc.abonar', 'cxc.reversar_abono'] as $p) {
        Permission::findOrCreate($p, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Cliente para cobranza. La deuda se cobra aunque el cliente esté inactivo,
 * deshabilitado para crédito o con otra configuración (datos históricos).
 */
function cobzCliente(bool $habilitado = true, string $limite = '10000.00', ?int $dias = 30, bool $activo = true): Cliente
{
    return Cliente::create([
        'nombre' => 'Cliente Cobranza',
        'activo' => $activo,
        'credito_habilitado' => $habilitado,
        'limite_credito' => $limite,
        'dias_credito' => $dias,
    ]);
}

function cobzVenta(Cliente $cliente, ?string $createdAt = null): Venta
{
    $venta = Venta::create([
        'user_id' => User::factory()->create()->id,
        'cliente_id' => $cliente->id,
        'total' => '1000.00',
        'forma_pago' => 'EFECTIVO',
    ]);

    if ($createdAt !== null) {
        DB::table('ventas')->where('id', $venta->id)->update(['created_at' => $createdAt]);
        $venta = $venta->fresh();
    }

    return $venta;
}

/**
 * Crea la CxC histórica vía el único punto de originación B15.2.
 */
function cobzCuenta(Cliente $cliente, int $centavos = 100000, ?User $actor = null): CuentaPorCobrar
{
    $venta = cobzVenta($cliente);

    return app(CuentaPorCobrarService::class)->crearParaVenta(
        $venta,
        $centavos,
        $actor ?? User::factory()->create()
    );
}

/**
 * Fixture de deuda histórica: crea la CxC y su CARGO_INICIAL directamente,
 * sin revalidar la originación B15.2. Así se puede cobrar a clientes
 * inactivos, deshabilitados para crédito o con otra configuración, y se puede
 * backdategear la venta para simular cuentas vencidas (fecha de vencimiento
 * derivada-por-carretera en el pasado, nunca escrita por UPDATE).
 */
function cobzCuentaHistorica(Cliente $cliente, int $centavos = 100000, ?string $createdAt = null): CuentaPorCobrar
{
    $venta = cobzVenta($cliente, $createdAt);
    $dias = (int) ($cliente->dias_credito ?? 30);

    $cuenta = CuentaPorCobrar::create([
        'venta_id' => $venta->id,
        'cliente_id' => $cliente->id,
        'importe_original_centavos' => $centavos,
        'saldo_centavos' => $centavos,
        'dias_credito_aplicados' => $dias,
        'fecha_vencimiento' => $venta->created_at->copy()->addDays($dias)->toDateString(),
        'estado' => CuentaPorCobrar::ESTADO_PENDIENTE,
    ]);

    MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => User::factory()->create()->id,
        'tipo' => MovimientoCxC::TIPO_CARGO_INICIAL,
        'monto_centavos' => $centavos,
        'saldo_antes_centavos' => 0,
        'saldo_despues_centavos' => $centavos,
        'metodo' => null,
        'referencia' => null,
        'movimiento_origen_id' => null,
    ]);

    return $cuenta;
}

function cobzActor(array $permisos = []): User
{
    $user = User::factory()->create();

    if ($permisos !== []) {
        $user->givePermissionTo($permisos);
    }

    return $user;
}

/**
 * ABONO directo vía el servicio (actor con su propia caja abierta si es EFECTIVO).
 */
function cobzAbono(
    CuentaPorCobrar $cuenta,
    int $montoCentavos,
    string $metodo,
    User $actor,
    ?string $referencia = null,
    string $uuid = 'x'
): MovimientoCxC {
    if ($metodo === MovimientoCxC::METODO_EFECTIVO) {
        openCajaFor($actor);
    }

    return app(CuentaPorCobrarService::class)->registrarAbono(
        $cuenta,
        $montoCentavos,
        $metodo,
        $actor,
        $referencia,
        'Observación de prueba',
        ($uuid === 'x' ? (string) Str::uuid() : $uuid)
    );
}

function cobzSesionAbierta(User $user): \App\Models\SesionCaja
{
    return app(CajaService::class)->sesionAbiertaDe($user);
}

/**
 * =========================
 * ABONO — dominio
 * =========================
 */
it('abono parcial EFECTIVO baja saldo, crea ledger y deja estado PARCIAL', function () {
    $cliente = cobzCliente();
    $cuenta = cobzCuenta($cliente);
    $actor = cobzActor();

    $abono = cobzAbono($cuenta, 25000, MovimientoCxC::METODO_EFECTIVO, $actor, 'EF-001');

    $cuenta = $cuenta->fresh();
    expect($cuenta->saldo_centavos)->toBe(75000);
    expect($cuenta->estado)->toBe(CuentaPorCobrar::ESTADO_PARCIAL);
    expect($abono->tipo)->toBe(MovimientoCxC::TIPO_ABONO);
    expect($abono->saldo_antes_centavos)->toBe(100000);
    expect($abono->saldo_despues_centavos)->toBe(75000);
    expect($abono->monto_centavos)->toBe(25000);
    expect($abono->metodo)->toBe(MovimientoCxC::METODO_EFECTIVO);
});

it('abono por el total deja la cuenta SALDADA', function () {
    $cuenta = cobzCuenta(cobzCliente());
    cobzAbono($cuenta, 100000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');

    $cuenta = $cuenta->fresh();
    expect($cuenta->saldo_centavos)->toBe(0);
    expect($cuenta->estado)->toBe(CuentaPorCobrar::ESTADO_SALDADA);
});

it('abono mayor al saldo se rechaza', function () {
    $cuenta = cobzCuenta(cobzCliente());

    expect(fn () => cobzAbono($cuenta, 100001, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1'))
        ->toThrow(DomainException::class, 'El abono supera el saldo de la cuenta.');
});

it('abono de monto cero o negativo se rechaza', function () {
    $cuenta = cobzCuenta(cobzCliente());

    expect(fn () => cobzAbono($cuenta, 0, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1'))
        ->toThrow(DomainException::class, 'debe registrar un monto mayor a cero');

    expect(fn () => cobzAbono($cuenta, -100, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1'))
        ->toThrow(DomainException::class, 'debe registrar un monto mayor a cero');
});

it('abono en cuenta SALDADA se rechaza', function () {
    $cuenta = cobzCuenta(cobzCliente());
    cobzAbono($cuenta, 100000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');

    expect(fn () => cobzAbono($cuenta, 1, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-2'))
        ->toThrow(DomainException::class, 'no admite abonos en su estado actual');
});

it('abono en cuenta CANCELADA se rechaza', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $cuenta->update(['saldo_centavos' => 0, 'estado' => CuentaPorCobrar::ESTADO_CANCELADA]);

    expect(fn () => cobzAbono($cuenta, 100000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1'))
        ->toThrow(DomainException::class, 'no admite abonos en su estado actual');
});

it('cliente inactivo (activo=false) con deuda histórica es cobrable', function () {
    $cliente = cobzCliente(activo: false);
    expect($cliente->activo)->toBeFalse();

    $cuenta = cobzCuentaHistorica($cliente);

    $abono = cobzAbono($cuenta, 40000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');

    expect($abono->monto_centavos)->toBe(40000);
    expect($cuenta->fresh()->saldo_centavos)->toBe(60000);
});

it('cliente con credito_habilitado=false es cobrable', function () {
    $cliente = cobzCliente(habilitado: false);
    expect($cliente->credito_habilitado)->toBeFalse();

    $cuenta = cobzCuentaHistorica($cliente);

    cobzAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');

    expect($cuenta->fresh()->saldo_centavos)->toBe(70000);
});

it('cliente con límite/plazo actual distinto al histórico es cobrable', function () {
    $cliente = cobzCliente(limite: '1.00', dias: 1);
    $cuenta = cobzCuentaHistorica($cliente, 50000);

    cobzAbono($cuenta, 50000, MovimientoCxC::METODO_TRANSFERENCIA, cobzActor(), 'ETE-9');

    expect($cuenta->fresh()->estado)->toBe(CuentaPorCobrar::ESTADO_SALDADA);
});

it('ABONO TARJETA no genera MovimientoCaja', function () {
    $cuenta = cobzCuenta(cobzCliente());

    cobzAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-5');

    expect(MovimientoCaja::count())->toBe(0);
});

it('ABONO TARJETA sin referencia se rechaza', function () {
    $cuenta = cobzCuenta(cobzCliente());

    expect(fn () => cobzAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, cobzActor(), null))
        ->toThrow(DomainException::class, 'requiere una referencia');
});

it('ABONO TRANSFERENCIA sin referencia se rechaza', function () {
    $cuenta = cobzCuenta(cobzCliente());

    expect(fn () => cobzAbono($cuenta, 30000, MovimientoCxC::METODO_TRANSFERENCIA, cobzActor(), null))
        ->toThrow(DomainException::class, 'requiere una referencia');
});

it('ABONO EFECTIVO genera MovimientoCaja ENTRADA vinculada por el mismo importe', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $actor = cobzActor();

    $abono = cobzAbono($cuenta, 25000, MovimientoCxC::METODO_EFECTIVO, $actor);

    $movCaja = MovimientoCaja::sole();
    expect($movCaja->tipo)->toBe(MovimientoCaja::TIPO_ABONO_CXC_EFECTIVO);
    expect($movCaja->direccion)->toBe(MovimientoCaja::DIR_ENTRADA);
    expect((string) $movCaja->monto)->toBe('250.00');
    expect($movCaja->movimiento_cxc_id)->toBe($abono->id);
    expect($movCaja->sesion_caja_id)->toBe(cobzSesionAbierta($actor)->id);
});

it('ABONO EFECTIVO refleja el efectivo real en caja', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $actor = cobzActor();

    cobzAbono($cuenta, 25000, MovimientoCxC::METODO_EFECTIVO, $actor);

    $esperado = app(CajaService::class)->calcularEfectivoEsperado(cobzSesionAbierta($actor));
    expect($esperado)->toBe(25000);
});

it('ABONO EFECTIVO sin caja abierta se rechaza', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $actor = cobzActor();

    expect(fn () => app(CuentaPorCobrarService::class)->registrarAbono(
        $cuenta,
        100,
        MovimientoCxC::METODO_EFECTIVO,
        $actor,
        null,
        null,
        (string) Str::uuid()
    ))->toThrow(DomainException::class, 'Debes abrir una caja');
});

it('ABONO EFECTIVO no puede usar la sesión de caja de otro usuario', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $actor = cobzActor();
    $otro = cobzActor();
    openCajaFor($otro);

    expect(fn () => app(CuentaPorCobrarService::class)->registrarAbono(
        $cuenta,
        100,
        MovimientoCxC::METODO_EFECTIVO,
        $actor,
        null,
        null,
        (string) Str::uuid()
    ))->toThrow(DomainException::class, 'Debes abrir una caja');
});

it('ABONO EFECTIVO se rechaza si la caja de la sesión fue desactivada (REV1 defense-in-depth)', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $actor = cobzActor();
    $sesion = openCajaFor($actor);

    // La sesión sigue ABIERTA pero su caja se desactiva (carrera legal: la BD
    // B14.3.1 impide estados más incoherentes, no impide desactivar una caja
    // con sesión abierta). REV1 revalida la relación bajo lock de SesionCaja.
    DB::table('cajas')->where('id', $sesion->caja_id)->update(['activa' => false]);

    expect(fn () => app(CuentaPorCobrarService::class)->registrarAbono(
        $cuenta,
        100,
        MovimientoCxC::METODO_EFECTIVO,
        $actor,
        null,
        null,
        (string) Str::uuid()
    ))->toThrow(DomainException::class, 'inactiva');

    expect(MovimientoCaja::count())->toBe(0);
});

it('referencia opcional en ABONO EFECTIVO', function () {
    $cuenta = cobzCuenta(cobzCliente());

    $abono = cobzAbono($cuenta, 10000, MovimientoCxC::METODO_EFECTIVO, cobzActor(), null);

    expect($abono->referencia)->toBeNull();
});

it('los ABONOS celebran su aritmética de saldo con centavos enteros', function () {
    $cuenta = cobzCuenta(cobzCliente(), 50000);

    cobzAbono($cuenta, 12345, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');
    cobzAbono($cuenta, 23456, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-2');

    $movimientos = $cuenta->movimientos()->orderBy('id')->get();

    expect($movimientos->get(1)->monto_centavos)->toBe(12345);
    expect($movimientos->get(1)->saldo_despues_centavos)->toBe(37655);
    expect($movimientos->get(2)->monto_centavos)->toBe(23456);
    expect($movimientos->get(2)->saldo_despues_centavos)->toBe(14199);
    expect($cuenta->fresh()->saldo_centavos)->toBe(14199);
});

/**
 * =========================
 * ABONO — PagoVenta barrier
 * =========================
 */
it('la cobranza NUNCA crea PagoVenta (deuda ≠ dinero)', function () {
    $pagosAntes = PagoVenta::count();

    $cuenta = cobzCuenta(cobzCliente());
    cobzAbono($cuenta, 30000, MovimientoCxC::METODO_EFECTIVO, cobzActor());
    cobzAbono($cuenta, 40000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');
    cobzAbono($cuenta, 30000, MovimientoCxC::METODO_TRANSFERENCIA, cobzActor(), 'ETE-1');

    expect(PagoVenta::count())->toBe($pagosAntes);
    expect(MovimientoCaja::count())->toBe(1);
});

/**
 * =========================
 * ABONO — idempotencia
 * =========================
 */
it('mismo UUID devuelve el mismo ABONO sin duplicar ledger ni caja', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $actor = cobzActor();
    $uuid = (string) Str::uuid();
    openCajaFor($actor);

    $primero = app(CuentaPorCobrarService::class)->registrarAbono(
        $cuenta,
        25000,
        MovimientoCxC::METODO_EFECTIVO,
        $actor,
        null,
        null,
        $uuid
    );

    $segundo = app(CuentaPorCobrarService::class)->registrarAbono(
        $cuenta,
        25000,
        MovimientoCxC::METODO_EFECTIVO,
        $actor,
        null,
        null,
        $uuid
    );

    expect($segundo->id)->toBe($primero->id);
    expect(MovimientoCxC::where('tipo', MovimientoCxC::TIPO_ABONO)->count())->toBe(1);
    expect(MovimientoCaja::count())->toBe(1);
    expect($cuenta->fresh()->saldo_centavos)->toBe(75000);
});

it('mismo UUID con datos incompatibles se rechaza', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $uuid = (string) Str::uuid();

    cobzAbono($cuenta, 25000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1', $uuid);

    expect(fn () => cobzAbono($cuenta, 90000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1', $uuid))
        ->toThrow(DomainException::class, 'datos incompatibles');

    expect(fn () => cobzAbono($cuenta, 25000, MovimientoCxC::METODO_EFECTIVO, cobzActor(), null, $uuid))
        ->toThrow(DomainException::class, 'datos incompatibles');
});

it('idempotencia EFECTIVO: retry del MISMO UUID tras cerrar caja reconoce el ABONO sin nueva caja', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $actor = cobzActor();
    openCajaFor($actor);
    $uuid = (string) Str::uuid();

    // 1) Primer request EFECTIVO: commit correcto.
    $abono = cobzAbono($cuenta, 25000, MovimientoCxC::METODO_EFECTIVO, $actor, null, $uuid);

    // 2) La caja se cierra ANTES del retry (escenario real de reintento).
    $sesionAbierta = cobzSesionAbierta($actor);
    DB::table('sesiones_caja')
        ->where('id', $sesionAbierta->id)
        ->update(['estado' => 'CERRADA']);

    expect(DB::table('sesiones_caja')->where('id', $sesionAbierta->id)->where('estado', 'ABIERTA')->exists())
        ->toBeFalse();

    // 3) Mismo request (mismo UUID): DEBE reconocer el ABONO existente sin
    //    requerir caja nueva, sin reducir saldo y sin crear filas nuevas.
    $segundo = app(CuentaPorCobrarService::class)->registrarAbono(
        $cuenta->fresh(),
        25000,
        MovimientoCxC::METODO_EFECTIVO,
        $actor,
        null,
        null,
        $uuid
    );

    expect($segundo->id)->toBe($abono->id);
    expect(MovimientoCxC::where('tipo', MovimientoCxC::TIPO_ABONO)->count())->toBe(1);
    expect(MovimientoCaja::count())->toBe(1);
    expect($cuenta->fresh()->saldo_centavos)->toBe(75000);
});

it('mismo UUID y misma referencia normalizada es idempotente', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $uuid = (string) Str::uuid();

    $a = cobzAbono($cuenta, 50000, MovimientoCxC::METODO_TARJETA, cobzActor(), ' TRX-001 ', $uuid);
    $b = cobzAbono($cuenta->fresh(), 50000, MovimientoCxC::METODO_TARJETA, cobzActor(), '  TRX-001', $uuid);

    expect($b->id)->toBe($a->id);
    expect($a->referencia)->toBe('TRX-001');
    expect(MovimientoCxC::where('tipo', MovimientoCxC::TIPO_ABONO)->count())->toBe(1);
    expect($cuenta->fresh()->saldo_centavos)->toBe(50000);
});

it('mismo UUID con referencia distinta es conflicto controlado, no retry', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $uuid = (string) Str::uuid();

    cobzAbono($cuenta, 50000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-001', $uuid);

    expect(fn () => cobzAbono($cuenta->fresh(), 50000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-999', $uuid))
        ->toThrow(DomainException::class, 'referencia distinta');
});

it('mismo UUID con método distinto y mismo monto es conflicto controlado', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $uuid = (string) Str::uuid();

    cobzAbono($cuenta, 50000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-001', $uuid);

    expect(fn () => cobzAbono($cuenta->fresh(), 50000, MovimientoCxC::METODO_TRANSFERENCIA, cobzActor(), 'TRX-001', $uuid))
        ->toThrow(DomainException::class, 'datos incompatibles');
});

it('mismo UUID usado en otra cuenta es conflicto controlado', function () {
    $uuid = (string) Str::uuid();
    cobzAbono(cobzCuenta(cobzCliente()), 10000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1', $uuid);

    expect(fn () => cobzAbono(cobzCuenta(cobzCliente()), 10000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1', $uuid))
        ->toThrow(DomainException::class, 'otra cuenta por cobrar');
});

it('UUID inválido por llamada directa al servicio se rechaza con DomainException (no QueryException)', function () {
    $cuenta = cobzCuenta(cobzCliente());

    expect(fn () => app(CuentaPorCobrarService::class)->registrarAbono(
        $cuenta,
        10000,
        MovimientoCxC::METODO_TARJETA,
        cobzActor(),
        null,
        null,
        'no-es-un-uuid-valido'
    ))->toThrow(DomainException::class, 'identificador de operación del abono es inválido');
});

it('el servicio revalida el grafo de la cuenta bajo lock (REV1 FIX 5)', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $actor = cobzActor();

    // Instancia STALE con un cliente_id que YA NO coincide con la fila real.
    $staleCliente = CuentaPorCobrar::find($cuenta->id);
    $staleCliente->cliente_id = cobzCliente()->id;

    expect(fn () => app(CuentaPorCobrarService::class)->registrarAbono(
        $staleCliente,
        10000,
        MovimientoCxC::METODO_TARJETA,
        $actor,
        'TRX-1',
        null,
        (string) Str::uuid()
    ))->toThrow(DomainException::class, 'grafo de la cuenta cambió');

    // Instancia STALE con un venta_id que ya no corresponde a la deuda.
    $staleVenta = CuentaPorCobrar::find($cuenta->id);
    $staleVenta->venta_id = cobzVenta(cobzCliente())->id;

    expect(fn () => app(CuentaPorCobrarService::class)->registrarAbono(
        $staleVenta,
        10000,
        MovimientoCxC::METODO_TARJETA,
        $actor,
        'TRX-1',
        null,
        (string) Str::uuid()
    ))->toThrow(DomainException::class, 'grafo de la cuenta cambió');
});

it('la BD rechaza la duplicación de operacion_uuid (barrera DB)', function () {
    $cuenta = cobzCuenta(cobzCliente());
    cobzAbono($cuenta, 40000, MovimientoCxC::METODO_EFECTIVO, cobzActor(), null, '00000000-0000-0000-0000-000000000001');

    $segundo = $cuenta->fresh();

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $segundo->id,
        'user_id' => cobzActor()->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 60000,
        'saldo_despues_centavos' => 20000,
        'metodo' => MovimientoCxC::METODO_EFECTIVO,
        'operacion_uuid' => '00000000-0000-0000-0000-000000000001',
    ]))->toThrow(QueryException::class);
});

it('operacion_uuid es nullable (filas históricas sin idempotencia)', function () {
    $cuenta = cobzCuenta(cobzCliente());

    $abono = MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => cobzActor()->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 10000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 90000,
        'metodo' => MovimientoCxC::METODO_TARJETA,
        'referencia' => 'TRX-0',
    ]);

    expect($abono->operacion_uuid)->toBeNull();
});

/**
 * =========================
 * REVERSA_ABONO — dominio
 * =========================
 */
it('reversa de ABONO TARJETA restaura el saldo sin tocar caja', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $admin = cobzActor();
    $abono = cobzAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $admin, 'TRX-1');

    $reversa = app(CuentaPorCobrarService::class)->reversarAbono($cuenta, $abono, $admin, 'Abono erróneo.');

    $cuenta = $cuenta->fresh();
    expect($cuenta->saldo_centavos)->toBe(100000);
    expect($cuenta->estado)->toBe(CuentaPorCobrar::ESTADO_PENDIENTE);

    expect($reversa->tipo)->toBe(MovimientoCxC::TIPO_REVERSA_ABONO);
    expect($reversa->monto_centavos)->toBe(30000);
    expect($reversa->saldo_antes_centavos)->toBe(70000);
    expect($reversa->saldo_despues_centavos)->toBe(100000);
    expect($reversa->movimiento_origen_id)->toBe($abono->id);
    expect($reversa->observaciones)->toBe('Abono erróneo.');
    expect($reversa->metodoOriginal())->toBe(MovimientoCxC::METODO_TARJETA);
    expect(MovimientoCaja::count())->toBe(0);
});

it('reversa parcial de lo cobrado reabre una cuenta SALDADA', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $admin = cobzActor();

    $abono = cobzAbono($cuenta, 100000, MovimientoCxC::METODO_TARJETA, $admin, 'TRX-1');
    expect($cuenta->fresh()->estado)->toBe(CuentaPorCobrar::ESTADO_SALDADA);

    app(CuentaPorCobrarService::class)->reversarAbono($cuenta->fresh(), $abono, $admin, 'Se reversó en su totalidad.');

    $cuenta = $cuenta->fresh();
    expect($cuenta->saldo_centavos)->toBe(100000);
    expect($cuenta->estado)->toBe(CuentaPorCobrar::ESTADO_PENDIENTE);
});

it('reversa devuelve estado PARCIAL si el saldo queda entre 0 y el original', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $admin = cobzActor();

    $abono1 = cobzAbono($cuenta, 40000, MovimientoCxC::METODO_TARJETA, $admin, 'TRX-1');

    // Abono que cruza: 100000 -> 60000 -> 10000
    cobzAbono($cuenta, 50000, MovimientoCxC::METODO_TARJETA, $admin, 'TRX-2');

    app(CuentaPorCobrarService::class)->reversarAbono($cuenta->fresh(), $abono1, $admin, 'Reversa del primer abono.');

    $cuenta = $cuenta->fresh();
    expect($cuenta->saldo_centavos)->toBe(50000);
    expect($cuenta->estado)->toBe(CuentaPorCobrar::ESTADO_PARCIAL);
});

it('reversa duplicada del mismo ABONO se rechaza', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $admin = cobzActor();
    $abono = cobzAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $admin, 'TRX-1');

    app(CuentaPorCobrarService::class)->reversarAbono($cuenta, $abono, $admin, 'Motivo uno');

    expect(fn () => app(CuentaPorCobrarService::class)->reversarAbono($cuenta, $abono, $admin, 'Motivo dos'))
        ->toThrow(DomainException::class, 'ya fue reversado');
});

it('solo puede reversarse un ABONO (no el CARGO_INICIAL)', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $inicial = $cuenta->movimientos()->where('tipo', MovimientoCxC::TIPO_CARGO_INICIAL)->firstOrFail();
    $admin = cobzActor();

    expect(fn () => app(CuentaPorCobrarService::class)->reversarAbono($cuenta, $inicial, $admin, 'No procede'))
        ->toThrow(DomainException::class, 'tipo ABONO');
});

it('reversa rechaza un ABONO de otra cuenta', function () {
    $cuenta1 = cobzCuenta(cobzCliente());
    $cuenta2 = cobzCuenta(cobzCliente());
    $admin = cobzActor();

    $abonoDeOtra = cobzAbono($cuenta1, 5000, MovimientoCxC::METODO_TARJETA, $admin, 'TRX-1');

    expect(fn () => app(CuentaPorCobrarService::class)->reversarAbono($cuenta2, $abonoDeOtra, $admin, 'No procede'))
        ->toThrow(DomainException::class, 'no pertenece a esta cuenta por cobrar');
});

it('reversa de ABONO en cuenta CANCELADA se rechaza', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $admin = cobzActor();
    $abono = cobzAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $admin, 'TRX-1');

    $cuenta->update(['saldo_centavos' => 0, 'estado' => CuentaPorCobrar::ESTADO_CANCELADA]);

    expect(fn () => app(CuentaPorCobrarService::class)->reversarAbono($cuenta->fresh(), $abono, $admin, 'No procede'))
        ->toThrow(DomainException::class, 'cuenta cancelada');
});

it('reversa sin motivo se rechaza', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $admin = cobzActor();
    $abono = cobzAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $admin, 'TRX-1');

    expect(fn () => app(CuentaPorCobrarService::class)->reversarAbono($cuenta, $abono, $admin, '   '))
        ->toThrow(DomainException::class, 'requiere un motivo');
});

/**
 * =========================
 * REVERSA_ABONO — EFECTIVO y caja
 * =========================
 */
it('reversa de ABONO EFECTIVO genera REVERSA_CXC_EFECTIVO SALIDA por el mismo importe', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $admin = cobzActor();
    $abono = cobzAbono($cuenta, 25000, MovimientoCxC::METODO_EFECTIVO, $admin);

    app(CuentaPorCobrarService::class)->reversarAbono($cuenta, $abono, $admin, 'Cobro por error.');

    $movCaja = MovimientoCaja::where('tipo', MovimientoCaja::TIPO_REVERSA_CXC_EFECTIVO)->sole();
    expect($movCaja->tipo)->toBe(MovimientoCaja::TIPO_REVERSA_CXC_EFECTIVO);
    expect($movCaja->direccion)->toBe(MovimientoCaja::DIR_SALIDA);
    expect((string) $movCaja->monto)->toBe('250.00');
    expect($movCaja->movimiento_cxc_id)->not->toBe($abono->id);

    $reversa = MovimientoCxC::where('tipo', MovimientoCxC::TIPO_REVERSA_ABONO)->sole();
    expect($movCaja->movimiento_cxc_id)->toBe($reversa->id);

    // El cajón refleja la reversa física (esperado de regreso al fondo).
    $esperado = app(CajaService::class)->calcularEfectivoEsperado(cobzSesionAbierta($admin));
    expect($esperado)->toBe(0);
});

it('reversa de ABONO EFECTIVO sin caja abierta se rechaza', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $admin = cobzActor();

    // ABONO EFECTIVO en la sesión del actor; luego se cierra la sesión por DB
    // (estado) y NO se abre otra: la reversa no encuentra caja disponible.
    $abono = cobzAbono($cuenta, 25000, MovimientoCxC::METODO_EFECTIVO, $admin);
    DB::table('sesiones_caja')
        ->where('id', cobzSesionAbierta($admin)->id)
        ->update(['estado' => 'CERRADA']);

    expect(fn () => app(CuentaPorCobrarService::class)->reversarAbono($cuenta, $abono, $admin, 'Cobro por error.'))
        ->toThrow(DomainException::class, 'Debes abrir una caja');

    expect(MovimientoCxC::where('tipo', MovimientoCxC::TIPO_REVERSA_ABONO)->count())->toBe(0);
});

it('reversa de ABONO EFECTIVO se rechaza si el efectivo esperado es insuficiente', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $admin = cobzActor();

    // El ABONO EFECTIVO vive en la caja de $admin. Quien intenta la reversa
    // desde OTRA caja (con fondo 0) no puede respaldar la salida física.
    $abono = cobzAbono($cuenta, 25000, MovimientoCxC::METODO_EFECTIVO, $admin);

    $otro = cobzActor();
    openCajaFor($otro);

    expect(fn () => app(CuentaPorCobrarService::class)->reversarAbono($cuenta, $abono, $otro, 'Cobro por error.'))
        ->toThrow(DomainException::class, 'efectivo disponible en caja es insuficiente');

    // Transacción íntegra: sin REVERSA huérfana, sin salida de caja fantasma
    // y sin tocar el saldo.
    expect(MovimientoCxC::where('tipo', MovimientoCxC::TIPO_REVERSA_ABONO)->count())->toBe(0);
    expect(MovimientoCaja::count())->toBe(1);
    expect($cuenta->fresh()->saldo_centavos)->toBe(75000);
});

it('la reversa corrige sin editar ni eliminar el ABONO original', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $admin = cobzActor();
    $abono = cobzAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $admin, 'TRX-1');

    app(CuentaPorCobrarService::class)->reversarAbono($cuenta, $abono, $admin, 'Corrección');

    $abono = MovimientoCxC::findOrFail($abono->id);
    expect($abono->monto_centavos)->toBe(30000);
    expect($abono->saldo_despues_centavos)->toBe(70000);

    expect(MovimientoCxC::where('tipo', MovimientoCxC::TIPO_ABONO)->count())->toBe(1);
    expect(MovimientoCxC::where('tipo', MovimientoCxC::TIPO_REVERSA_ABONO)->count())->toBe(1);

    // Append-only: el ledger no se puede reescribir ni retirar.
    expect(fn () => $abono->update(['monto_centavos' => 1]))->toThrow(DomainException::class);
    expect(fn () => $abono->delete())->toThrow(DomainException::class);
    expect(fn () => DB::table('movimientos_cxc')->where('id', $abono->id)->update(['monto_centavos' => 1]))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('movimientos_cxc')->where('id', $abono->id)->delete())
        ->toThrow(QueryException::class);
});

it('no se puede eliminar una CuentaPorCobrar', function () {
    $cuenta = cobzCuenta(cobzCliente());

    expect(fn () => $cuenta->delete())->toThrow(DomainException::class);
});

/**
 * =========================
 * Barreras BD (trigger caja <-> CxC y CHECKs)
 * =========================
 */
it('trigger de caja rechaza ABONO_CXC_EFECTIVO sin vínculo', function () {
    $usuario = cobzActor();
    $sesion = openCajaFor($usuario);

    expect(fn () => MovimientoCaja::create([
        'sesion_caja_id' => $sesion->id,
        'user_id' => $usuario->id,
        'tipo' => MovimientoCaja::TIPO_ABONO_CXC_EFECTIVO,
        'direccion' => MovimientoCaja::DIR_ENTRADA,
        'monto' => '100.00',
        'concepto' => 'Sin vínculo.',
        'movimiento_cxc_id' => null,
    ]))->toThrow(QueryException::class);
});

it('trigger de caja rechaza ABONO_CXC_EFECTIVO ligado a un ABONO TARJETA', function () {
    $usuario = cobzActor();
    $sesion = openCajaFor($usuario);
    $cuenta = cobzCuenta(cobzCliente());

    $abonoTarjeta = cobzAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $usuario, 'TRX-1');

    expect(fn () => MovimientoCaja::create([
        'sesion_caja_id' => $sesion->id,
        'user_id' => $usuario->id,
        'tipo' => MovimientoCaja::TIPO_ABONO_CXC_EFECTIVO,
        'direccion' => MovimientoCaja::DIR_ENTRADA,
        'monto' => '300.00',
        'concepto' => 'Vínculo incoherente.',
        'movimiento_cxc_id' => $abonoTarjeta->id,
    ]))->toThrow(QueryException::class);
});

it('trigger de caja rechaza ABONO_CXC_EFECTIVO con importe distinto al ABONO', function () {
    $usuario = cobzActor();
    $sesion = openCajaFor($usuario);
    $cuenta = cobzCuenta(cobzCliente());

    $abono = cobzAbono($cuenta, 25000, MovimientoCxC::METODO_EFECTIVO, $usuario);

    expect(fn () => MovimientoCaja::create([
        'sesion_caja_id' => $sesion->id,
        'user_id' => $usuario->id,
        'tipo' => MovimientoCaja::TIPO_ABONO_CXC_EFECTIVO,
        'direccion' => MovimientoCaja::DIR_ENTRADA,
        'monto' => '250.01',
        'concepto' => 'Importe distinto.',
        'movimiento_cxc_id' => $abono->id,
    ]))->toThrow(QueryException::class);
});

it('trigger de caja rechaza REVERSA_CXC_EFECTIVO de una reversa cuyo ABONO origen no era EFECTIVO', function () {
    $usuario = cobzActor();
    $sesion = openCajaFor($usuario);
    $cuenta = cobzCuenta(cobzCliente());

    $abonoTarjeta = cobzAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $usuario, 'TRX-1');
    $reversa = app(CuentaPorCobrarService::class)->reversarAbono($cuenta, $abonoTarjeta, $usuario, 'Corrección.');

    expect(fn () => MovimientoCaja::create([
        'sesion_caja_id' => $sesion->id,
        'user_id' => $usuario->id,
        'tipo' => MovimientoCaja::TIPO_REVERSA_CXC_EFECTIVO,
        'direccion' => MovimientoCaja::DIR_SALIDA,
        'monto' => '300.00',
        'concepto' => 'Reversa de tarjeta como efectivo.',
        'movimiento_cxc_id' => $reversa->id,
    ]))->toThrow(QueryException::class);
});

it('trigger de caja rechaza otros tipos con movimiento_cxc_id no nulo', function () {
    $usuario = cobzActor();
    $sesion = openCajaFor($usuario);
    $cuenta = cobzCuenta(cobzCliente());
    $abono = cobzAbono($cuenta, 25000, MovimientoCxC::METODO_EFECTIVO, $usuario);

    expect(fn () => MovimientoCaja::create([
        'sesion_caja_id' => $sesion->id,
        'user_id' => $usuario->id,
        'tipo' => MovimientoCaja::TIPO_COBRO_EFECTIVO,
        'direccion' => MovimientoCaja::DIR_ENTRADA,
        'monto' => '250.00',
        'concepto' => 'Tipo sin vínculo permitido.',
        'movimiento_cxc_id' => $abono->id,
    ]))->toThrow(QueryException::class);
});

it('los CHECKs de movimientos_caja admiten la dirección correcta de los nuevos tipos', function () {
    expect(MovimientoCaja::direccionDeTipo(MovimientoCaja::TIPO_ABONO_CXC_EFECTIVO))->toBe(MovimientoCaja::DIR_ENTRADA);
    expect(MovimientoCaja::direccionDeTipo(MovimientoCaja::TIPO_REVERSA_CXC_EFECTIVO))->toBe(MovimientoCaja::DIR_SALIDA);
});

it('down() de la migración B15.4 revierte con datos sin borrar filas ni ledger (REV1 FIX 4)', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $admin = cobzActor();

    // ABONO EFECTIVO + su reversa EFECTIVA => 2 filas de caja B15.4.
    $abono = cobzAbono($cuenta, 25000, MovimientoCxC::METODO_EFECTIVO, $admin);
    app(CuentaPorCobrarService::class)->reversarAbono($cuenta, $abono, $admin, 'Rollback físico.');

    $totalAntes = MovimientoCaja::count();
    $ledgerAntes = MovimientoCxC::count();
    expect(MovimientoCaja::whereIn('tipo', [
        MovimientoCaja::TIPO_ABONO_CXC_EFECTIVO,
        MovimientoCaja::TIPO_REVERSA_CXC_EFECTIVO,
    ])->count())->toBe(2);

    // Ejecutar el down() REAL de la migración B15.4 (no una réplica manual).
    // PostgreSQL ejecuta DDL de forma transaccional, por lo que down() es
    // seguro dentro del RefreshDatabase (cada test corre en una transacción
    // que se revierte al terminar).
    $migration = require database_path('migrations/2026_09_01_101000_add_cxc_vinculo_to_movimientos_caja.php');
    $migration->down();

    // El trigger/función B15.4 ya no existen.
    expect(DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_trigger WHERE tgname = 'mvcaja_cxc_vinculo') AS exists")->exists)
        ->toBeFalse();

    // La columna/FK/índice B15.4 quedaron eliminados por down().
    expect(\Illuminate\Support\Facades\Schema::hasColumn('movimientos_caja', 'movimiento_cxc_id'))->toBeFalse();

    // NO se borró nada: ni movimientos físicos ni ledger económico.
    expect(MovimientoCaja::count())->toBe($totalAntes);
    expect(MovimientoCxC::count())->toBe($ledgerAntes);

    // Las filas B15.4 se DEGRADARON a tipos B14 conservando el efecto físico
    // (monto, dirección, sesión, user, concepto, referencia, created_at) y
    // perdieron únicamente el vínculo/tipo semántico B15.4.
    $entrada = MovimientoCaja::where('tipo', 'ENTRADA_MANUAL')->sole();
    expect(\App\Support\Money::aCentavos((string) $entrada->monto))->toBe(25000);
    expect($entrada->direccion)->toBe(MovimientoCaja::DIR_ENTRADA);
    expect(\App\Support\Money::aCentavos((string) $entrada->monto))->toBeGreaterThan(0);

    $salida = MovimientoCaja::where('tipo', 'RETIRO')->sole();
    expect(\App\Support\Money::aCentavos((string) $salida->monto))->toBe(25000);
    expect($salida->direccion)->toBe(MovimientoCaja::DIR_SALIDA);

    // Tras down() no puede quedar un vínculo semántico: la columna no existe.
    // Además, los CHECKs B14 (restaurados por down()) admiten las filas
    // degradadas, lo que demuestra que el rollback es posible con datos.
    $checkDef = DB::selectOne(
        "SELECT pg_get_constraintdef(oid) AS def
         FROM pg_constraint
         WHERE conname = 'movimientos_caja_tipo_check'"
    )->def;
    expect($checkDef)->toContain('ENTRADA_MANUAL')->toContain('RETIRO')->not->toContain('ABONO_CXC_EFECTIVO');
});

/**
 * =========================
 * Estado derivado y vencimiento
 * =========================
 */
it('estadoNormalDesdeSaldo deriva PENDIENTE/PARCIAL/SALDADA sin persistir CANCELADA', function () {
    expect(CuentaPorCobrar::estadoNormalDesdeSaldo(100000, 100000))->toBe(CuentaPorCobrar::ESTADO_PENDIENTE);
    expect(CuentaPorCobrar::estadoNormalDesdeSaldo(100000, 40000))->toBe(CuentaPorCobrar::ESTADO_PARCIAL);
    expect(CuentaPorCobrar::estadoNormalDesdeSaldo(100000, 0))->toBe(CuentaPorCobrar::ESTADO_SALDADA);

    expect(fn () => CuentaPorCobrar::estadoNormalDesdeSaldo(100000, 100001))
        ->toThrow(DomainException::class);
    expect(fn () => CuentaPorCobrar::estadoNormalDesdeSaldo(100000, -1))
        ->toThrow(DomainException::class);
});

it('VENCIDA es derivada: vence, deja de serlo al saldar', function () {
    $cliente = cobzCliente(dias: 1);

    // Venta con 20 días de antigüedad -> vencimiento hace 19 días.
    $cuenta = cobzCuentaHistorica($cliente, 100000, now()->subDays(20)->toDateString());

    expect($cuenta->esVencida())->toBeTrue();

    cobzAbono($cuenta, 100000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');

    expect($cuenta->fresh()->esVencida())->toBeFalse();
});

it('una cuenta CANCELADA nunca es VENCIDA', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $cuenta->update(['saldo_centavos' => 0, 'estado' => CuentaPorCobrar::ESTADO_CANCELADA]);

    expect($cuenta->fresh()->esVencida())->toBeFalse();
});

/**
 * =========================
 * HTTP / permisos y vistas
 * =========================
 */
it('Ventas consulta el listado y el detalle de CxC', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $user = cobzActor(['cxc.ver']);

    $this->actingAs($user)->get(route('cxc.index'))->assertOk();
    $this->actingAs($user)->get(route('cxc.show', $cuenta))->assertOk()->assertSee($cuenta->folio);
});

it('Auditor consulta CxC en solo lectura', function () {
    $user = cobzActor(['cxc.ver']);
    $cuenta = cobzCuenta(cobzCliente());

    $this->actingAs($user)
        ->post(route('cxc.abonos.store', $cuenta), [
            'monto' => '100.00',
            'metodo' => 'TARJETA',
            'referencia' => 'TRX-1',
            'operacion_uuid' => (string) Str::uuid(),
        ])
        ->assertForbidden();
});

it('Almacen recibe 403 en CxC', function () {
    $user = cobzActor();
    $cuenta = cobzCuenta(cobzCliente());

    $this->actingAs($user)->get(route('cxc.index'))->assertForbidden();
    $this->actingAs($user)->get(route('cxc.show', $cuenta))->assertForbidden();
});

it('Almacen no puede abonar ni reversar (403)', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $abono = cobzAbono($cuenta, 10000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');
    $user = cobzActor();

    $this->actingAs($user)
        ->post(route('cxc.abonos.store', $cuenta), [
            'monto' => '10.00',
            'metodo' => 'TARJETA',
            'referencia' => 'TRX-2',
            'operacion_uuid' => (string) Str::uuid(),
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('cxc.abonos.reversar', [$cuenta, $abono]), ['motivo' => 'No autorizado.'])
        ->assertForbidden();
});

it('Ventas puede abonar pero no reversar', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $abono = cobzAbono($cuenta, 10000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');
    $ventas = cobzActor(['cxc.ver', 'cxc.abonar']);

    $this->actingAs($ventas)
        ->post(route('cxc.abonos.store', $cuenta), [
            'monto' => '200.00',
            'metodo' => 'TARJETA',
            'referencia' => 'TRX-2',
            'operacion_uuid' => (string) Str::uuid(),
        ])
        ->assertRedirect(route('cxc.show', $cuenta));

    expect($cuenta->fresh()->saldo_centavos)->toBe(70000);

    $this->actingAs($ventas)
        ->post(route('cxc.abonos.reversar', [$cuenta, $abono]), ['motivo' => 'No autorizado.'])
        ->assertForbidden();
});

it('Admin real con cxc.reversar_abono puede reversar', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $abono = cobzAbono($cuenta, 10000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');

    $role = Role::findOrCreate('Admin', 'web');
    $admin = cobzActor(['cxc.ver', 'cxc.abonar', 'cxc.reversar_abono']);
    $admin->assignRole($role);

    expect(CxCAcceso::puedeReversar($admin))->toBeTrue();

    $this->actingAs($admin)
        ->post(route('cxc.abonos.reversar', [$cuenta, $abono]), ['motivo' => 'Cobro erróneo.'])
        ->assertRedirect(route('cxc.show', $cuenta));

    expect($cuenta->fresh()->saldo_centavos)->toBe(100000);
});

it('reversa exige rol Admin: permiso directo sin rol NO alcanza (403)', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $abono = cobzAbono($cuenta, 10000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');

    $conPermisoDirecto = cobzActor(['cxc.ver', 'cxc.abonar', 'cxc.reversar_abono']);

    // Tiene el permiso, pero NO es rol Admin -> invariante FIX 1 incumplido.
    expect($conPermisoDirecto->hasPermissionTo('cxc.reversar_abono'))->toBeTrue();
    expect(CxCAcceso::puedeReversar($conPermisoDirecto))->toBeFalse();

    $this->actingAs($conPermisoDirecto)
        ->post(route('cxc.abonos.reversar', [$cuenta, $abono]), ['motivo' => 'Cobro erróneo.'])
        ->assertForbidden();

    expect($cuenta->fresh()->saldo_centavos)->toBe(90000);
});

it('Auditor no puede reversar (403)', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $abono = cobzAbono($cuenta, 10000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');

    $auditor = cobzActor(['cxc.ver']);

    $this->actingAs($auditor)
        ->post(route('cxc.abonos.reversar', [$cuenta, $abono]), ['motivo' => 'No autorizado.'])
        ->assertForbidden();
});

it('HTTP: abono mayor al saldo devuelve error de validación controlado', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $user = cobzActor(['cxc.ver', 'cxc.abonar']);

    $this->actingAs($user)
        ->post(route('cxc.abonos.store', $cuenta), [
            'monto' => '5000.00',
            'metodo' => 'TARJETA',
            'referencia' => 'TRX-1',
            'operacion_uuid' => (string) Str::uuid(),
        ])
        ->assertSessionHasErrors('monto');

    expect(MovimientoCxC::where('tipo', MovimientoCxC::TIPO_ABONO)->count())->toBe(0);
});

it('HTTP: abono TARJETA sin referencia no se registra', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $user = cobzActor(['cxc.ver', 'cxc.abonar']);

    $this->actingAs($user)
        ->post(route('cxc.abonos.store', $cuenta), [
            'monto' => '100.00',
            'metodo' => 'TARJETA',
            'operacion_uuid' => (string) Str::uuid(),
        ])
        ->assertSessionHasErrors('referencia');
});

it('un usuario sin autenticación es redirigido al login', function () {
    $this->get(route('cxc.index'))->assertRedirect(route('login'));
});

/**
 * =========================
 * Índice: resumen, filtros y derivados
 * =========================
 */
it('el resumen del índice usa centavos exactos y cuentas con saldo', function () {
    $cliente = cobzCliente(dias: 1);
    $vencida = cobzCuentaHistorica($cliente, 30000, now()->subDays(2)->toDateString());

    $activa = cobzCuenta(cobzCliente(), 10000);
    cobzAbono($activa, 4000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');

    $user = cobzActor(['cxc.ver']);

    $this->actingAs($user)->get(route('cxc.index'))
        ->assertOk()
        ->assertViewHas('saldoTotal', '360.00')
        ->assertViewHas('saldoVencido', '300.00')
        ->assertViewHas('cuentasActivas', 2);
});

it('el índice filtra por estado, con saldo y vencidas', function () {
    $vencida = cobzCuentaHistorica(
        cobzCliente(dias: 1),
        30000,
        now()->subDays(2)->toDateString()
    );

    $parcial = cobzCuenta(cobzCliente(), 10000);
    cobzAbono($parcial, 4000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');

    $saldada = cobzCuenta(cobzCliente(), 10000);
    cobzAbono($saldada, 10000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-2');

    $user = cobzActor(['cxc.ver']);

    $this->actingAs($user)->get(route('cxc.index', ['estado' => 'PARCIAL']))
        ->assertOk()
        ->assertViewHas('cuentas', fn ($p) => $p->total() === 1);

    $this->actingAs($user)->get(route('cxc.index', ['vencidas' => '1']))
        ->assertOk()
        ->assertViewHas('cuentas', fn ($p) => $p->total() === 1);

    $this->actingAs($user)->get(route('cxc.index', ['con_saldo' => '1']))
        ->assertOk()
        ->assertViewHas('cuentas', fn ($p) => $p->total() === 2);
});

it('el detalle expone el ledger cronológico con método derivado', function () {
    $cuenta = cobzCuenta(cobzCliente());
    $admin = cobzActor();

    $abono = cobzAbono($cuenta, 30000, MovimientoCxC::METODO_TARJETA, $admin, 'TRX-1');
    app(CuentaPorCobrarService::class)->reversarAbono($cuenta, $abono, $admin, 'Corrección');

    $user = cobzActor(['cxc.ver']);

    $this->actingAs($user)->get(route('cxc.show', $cuenta))
        ->assertOk()
        ->assertSee('CARGO_INICIAL')
        ->assertSee('ABONO')
        ->assertSee('REVERSA_ABONO');
});

/**
 * =========================
 * Postventa debt-first con CxC (B15.5) y guards
 * =========================
 */
it('la postventa con CxC cancela la deuda y restituye el dinero de los abonos (B15.5 debt-first)', function () {
    $this->actingAs(cobzActor());

    $item = Item::create(['estado' => 'DISPONIBLE', 'precio' => 500.0]);
    $item2 = Item::create(['estado' => 'DISPONIBLE', 'precio' => 500.0]);
    $cliente = cobzCliente();

    $cuenta = cobzCuenta($cliente, 100000);
    $venta = $cuenta->venta;

    VentaDetalle::create(['venta_id' => $venta->id, 'item_id' => $item->id, 'precio' => '500.00']);
    VentaDetalle::create(['venta_id' => $venta->id, 'item_id' => $item2->id, 'precio' => '500.00']);
    $item->update(['estado' => 'VENDIDO']);
    $item2->update(['estado' => 'VENDIDO']);

    $abonoViejo = cobzAbono($cuenta, 40000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-1');
    $abonoNuevo = cobzAbono($cuenta, 40000, MovimientoCxC::METODO_TARJETA, cobzActor(), 'TRX-2');

    $documento = app(PostventaService::class)->cancelar(
        $venta,
        'Quisiera cancelar.',
        null,
        [],
        null,
        [(int) $abonoViejo->id => 'REF-1', (int) $abonoNuevo->id => 'REF-2'],
    );

    expect($documento->tipo)->toBe(DocumentoPostventa::TIPO_CANCELACION);
    expect($cuenta->refresh()->saldo_centavos)->toBe(0);
    expect($cuenta->estado)->toBe(CuentaPorCobrar::ESTADO_CANCELADA);

    $deuda = MovimientoCxC::where('cuenta_por_cobrar_id', $cuenta->id)
        ->where('tipo', MovimientoCxC::TIPO_CANCELACION)
        ->first();
    expect($deuda)->not->toBeNull();
    expect($deuda->monto_centavos)->toBe(20000);
    expect($deuda->documento_postventa_id)->toBe($documento->id);

    expect($documento->reembolsos)->toHaveCount(2);
    expect($documento->reembolsos->pluck('origen')->all())->each->toBe(ReembolsoPostventa::ORIGEN_CXC_ABONO);
    expect($documento->reembolsos->pluck('monto')->map(
        fn ($m) => Money::aCentavos((string) $m)
    )->sortDesc()->values()->all())->toBe([40000, 40000]);
    expect($documento->reembolsos->pluck('movimiento_cxc_id')->sort()->values()->all())
        ->toBe([(int) $abonoViejo->id, (int) $abonoNuevo->id]);

    expect($venta->refresh()->estado)->toBe(Venta::ESTADO_CANCELADA);
    expect($item->refresh()->estado)->toBe('DISPONIBLE');
    expect($item2->refresh()->estado)->toBe('DISPONIBLE');
    $this->assertDatabaseCount('movimientos_caja', 0);
});

it('CxCAcceso reserva la reversa al rol Admin y no la otorga a otro rol', function () {
    CxCAcceso::assertRolesSeguros([
        'Admin' => ['cxc.ver', 'cxc.abonar', 'cxc.reversar_abono'],
        'Ventas' => ['cxc.ver', 'cxc.abonar'],
        'Auditor' => ['cxc.ver'],
        'Almacen' => [],
    ]);

    expect(fn () => CxCAcceso::assertRolesSeguros([
        'Admin' => ['cxc.ver'],
        'Ventas' => ['cxc.reversar_abono'],
    ]))->toThrow(\InvalidArgumentException::class);
});
