<?php

use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\DocumentoPostventa;
use App\Models\MovimientoCxC;
use App\Models\User;
use App\Models\Venta;
use App\Services\CuentaPorCobrarService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function mxcCliente(): Cliente
{
    return Cliente::create([
        'nombre' => 'Cli CxC',
        'credito_habilitado' => true,
        'limite_credito' => '1000.00',
        'dias_credito' => 30,
    ]);
}

function mxcUsuario(): User
{
    return User::factory()->create();
}

function mxcCuenta(): CuentaPorCobrar
{
    $cliente = mxcCliente();
    $venta = Venta::create([
        'user_id' => User::factory()->create()->id,
        'cliente_id' => $cliente->id,
        'total' => '1000.00',
        'forma_pago' => 'EFECTIVO',
    ]);

    return app(CuentaPorCobrarService::class)->crearParaVenta($venta, 100000, mxcUsuario());
}

function mxcDocumento(CuentaPorCobrar $cuenta, string $tipo, string $total): DocumentoPostventa
{
    return DocumentoPostventa::create([
        'venta_id' => $cuenta->venta_id,
        'tipo' => $tipo,
        'user_id' => mxcUsuario()->id,
        'motivo' => 'Doc para ledger CxC.',
        'forma_reembolso' => DocumentoPostventa::FORMA_EFECTIVO,
        'total' => $total,
    ]);
}

it('efectoDeTipo mapea los cinco tipos', function () {
    expect(MovimientoCxC::efectoDeTipo(MovimientoCxC::TIPO_CARGO_INICIAL))->toBe(1);
    expect(MovimientoCxC::efectoDeTipo(MovimientoCxC::TIPO_REVERSA_ABONO))->toBe(1);
    expect(MovimientoCxC::efectoDeTipo(MovimientoCxC::TIPO_ABONO))->toBe(-1);
    expect(MovimientoCxC::efectoDeTipo(MovimientoCxC::TIPO_REDUCCION_POSTVENTA))->toBe(-1);
    expect(MovimientoCxC::efectoDeTipo(MovimientoCxC::TIPO_CANCELACION))->toBe(-1);
});

it('efectoDeTipo lanza DomainException para tipo desconocido', function () {
    expect(fn () => MovimientoCxC::efectoDeTipo('DESCONOCIDO'))->toThrow(DomainException::class);
});

it('monto debe ser mayor que cero', function () {
    $cuenta = mxcCuenta();

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 0,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 100000,
        'metodo' => 'EFECTIVO',
    ]))->toThrow(QueryException::class);
});

it('saldos no pueden ser negativos', function () {
    $cuenta = mxcCuenta();

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 200000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => -100000,
        'metodo' => 'EFECTIVO',
    ]))->toThrow(QueryException::class);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 1000,
        'saldo_antes_centavos' => -5,
        'saldo_despues_centavos' => -1005,
        'metodo' => 'EFECTIVO',
    ]))->toThrow(QueryException::class);
});

it('CARGO_INICIAL obliga before=0 y after=monto', function () {
    $cuenta = mxcCuenta();
    $mov = MovimientoCxC::where('tipo', MovimientoCxC::TIPO_CARGO_INICIAL)->firstOrFail();
    expect($mov->saldo_antes_centavos)->toBe(0);
    expect($mov->saldo_despues_centavos)->toBe($mov->monto_centavos);

    // Estructura inválida -> rechazada por aritmética ledger.
    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_CARGO_INICIAL,
        'monto_centavos' => 5000,
        'saldo_antes_centavos' => 100,
        'saldo_despues_centavos' => 5100,
    ]))->toThrow(QueryException::class);
});

it('solo puede existir un CARGO_INICIAL por CxC', function () {
    $cuenta = mxcCuenta();

    // El servicio ya creó exactamente uno.
    expect(MovimientoCxC::where('tipo', MovimientoCxC::TIPO_CARGO_INICIAL)->count())->toBe(1);

    // Un segundo CARGO_INICIAL es rechazado por el UNIQUE parcial.
    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_CARGO_INICIAL,
        'monto_centavos' => 1000,
        'saldo_antes_centavos' => 0,
        'saldo_despues_centavos' => 1000,
    ]))->toThrow(QueryException::class);
});

it('ABONO: after = before - monto', function () {
    $cuenta = mxcCuenta();

    $abono = MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 60000,
        'metodo' => 'EFECTIVO',
    ]);

    expect($abono->saldo_despues_centavos)->toBe(60000);
});

it('ABONO exige método', function () {
    $cuenta = mxcCuenta();

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 60000,
        'metodo' => null,
    ]))->toThrow(QueryException::class);
});

it('método solo EFECTIVO/TARJETA/TRANSFERENCIA', function () {
    $cuenta = mxcCuenta();

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 60000,
        'metodo' => 'CREDITO',
    ]))->toThrow(QueryException::class);
});

it('tipos no ABONO obligan metodo NULL', function () {
    $cuenta = mxcCuenta();
    $doc = mxcDocumento($cuenta, DocumentoPostventa::TIPO_DEVOLUCION, '400.00');

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_REDUCCION_POSTVENTA,
        'monto_centavos' => 10000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 90000,
        'metodo' => 'EFECTIVO',
        'documento_postventa_id' => $doc->id,
    ]))->toThrow(QueryException::class);
});

it('REVERSA_ABONO exige origen', function () {
    $cuenta = mxcCuenta();

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_REVERSA_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 60000,
        'saldo_despues_centavos' => 100000,
        'movimiento_origen_id' => null,
    ]))->toThrow(QueryException::class);
});

it('tipos no REVERSA obligan origen NULL', function () {
    $cuenta = mxcCuenta();

    $abono = MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 60000,
        'metodo' => 'EFECTIVO',
    ]);
    $doc = mxcDocumento($cuenta, DocumentoPostventa::TIPO_DEVOLUCION, '400.00');

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_REDUCCION_POSTVENTA,
        'monto_centavos' => 10000,
        'saldo_antes_centavos' => 60000,
        'saldo_despues_centavos' => 50000,
        'movimiento_origen_id' => $abono->id,
        'documento_postventa_id' => $doc->id,
    ]))->toThrow(QueryException::class);
});

it('trigger reversa rechaza origen que no es ABONO', function () {
    $cuenta = mxcCuenta();
    $inicial = MovimientoCxC::where('tipo', MovimientoCxC::TIPO_CARGO_INICIAL)->firstOrFail();

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_REVERSA_ABONO,
        'monto_centavos' => $inicial->monto_centavos,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 200000,
        'movimiento_origen_id' => $inicial->id,
    ]))->toThrow(QueryException::class);
});

it('trigger reversa rechaza ABONO de otra CxC', function () {
    $cuenta1 = mxcCuenta();
    $cuenta2 = mxcCuenta();

    $abono = MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta1->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 60000,
        'metodo' => 'EFECTIVO',
    ]);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta2->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_REVERSA_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 140000,
        'movimiento_origen_id' => $abono->id,
    ]))->toThrow(QueryException::class);
});

it('trigger reversa rechaza monto distinto al ABONO original', function () {
    $cuenta = mxcCuenta();

    $abono = MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 60000,
        'metodo' => 'EFECTIVO',
    ]);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_REVERSA_ABONO,
        'monto_centavos' => 50000,
        'saldo_antes_centavos' => 60000,
        'saldo_despues_centavos' => 110000,
        'movimiento_origen_id' => $abono->id,
    ]))->toThrow(QueryException::class);
});

it('unique parcial rechaza segunda reversa del mismo ABONO', function () {
    $cuenta = mxcCuenta();
    $user = mxcUsuario();

    $abono = MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => $user->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 60000,
        'metodo' => 'EFECTIVO',
    ]);

    MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => $user->id,
        'tipo' => MovimientoCxC::TIPO_REVERSA_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 60000,
        'saldo_despues_centavos' => 100000,
        'movimiento_origen_id' => $abono->id,
    ]);

    // Una sola REVERSA_ABONO para ese ABONO.
    expect(MovimientoCxC::where('tipo', MovimientoCxC::TIPO_REVERSA_ABONO)->count())->toBe(1);

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => $user->id,
        'tipo' => MovimientoCxC::TIPO_REVERSA_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 140000,
        'movimiento_origen_id' => $abono->id,
    ]))->toThrow(QueryException::class);
});

it('REVERSA correcta: after = before + monto', function () {
    $cuenta = mxcCuenta();
    $user = mxcUsuario();

    $abono = MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => $user->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 60000,
        'metodo' => 'EFECTIVO',
    ]);

    $reversa = MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => $user->id,
        'tipo' => MovimientoCxC::TIPO_REVERSA_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 60000,
        'saldo_despues_centavos' => 100000,
        'movimiento_origen_id' => $abono->id,
    ]);

    expect($reversa->metodo)->toBeNull();
    expect($reversa->movimientoOrigen->id)->toBe($abono->id);
    expect($reversa->cuentaPorCobrar->id)->toBe($cuenta->id);
});

it('CANCELACION exige monto = before y after = 0', function () {
    $cuenta = mxcCuenta();
    $doc = mxcDocumento($cuenta, DocumentoPostventa::TIPO_CANCELACION, '1000.00');

    $ok = MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_CANCELACION,
        'monto_centavos' => 100000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 0,
        'documento_postventa_id' => $doc->id,
    ]);
    expect($ok->saldo_despues_centavos)->toBe(0);

    // monto != before -> rechazada (con documento válido, falla la aritmética).
    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_CANCELACION,
        'monto_centavos' => 50000,
        'saldo_antes_centavos' => 60000,
        'saldo_despues_centavos' => 10000,
        'documento_postventa_id' => $doc->id,
    ]))->toThrow(QueryException::class);
});

it('REDUCCION_POSTVENTA: after = before - monto', function () {
    $cuenta = mxcCuenta();
    $doc = mxcDocumento($cuenta, DocumentoPostventa::TIPO_DEVOLUCION, '400.00');

    $mov = MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_REDUCCION_POSTVENTA,
        'monto_centavos' => 20000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 80000,
        'documento_postventa_id' => $doc->id,
    ]);

    expect($mov->saldo_despues_centavos)->toBe(80000);
});

it('ledger rechaza cualquier aritmética inconsistente', function () {
    $cuenta = mxcCuenta();

    expect(fn () => MovimientoCxC::create([
        'cuenta_por_cobrar_id' => $cuenta->id,
        'user_id' => mxcUsuario()->id,
        'tipo' => MovimientoCxC::TIPO_ABONO,
        'monto_centavos' => 40000,
        'saldo_antes_centavos' => 100000,
        'saldo_despues_centavos' => 70000, // esperaba 60000
        'metodo' => 'EFECTIVO',
    ]))->toThrow(QueryException::class);
});

it('MovimientoCxC no puede actualizarse vía Eloquent', function () {
    $cuenta = mxcCuenta();
    $mov = MovimientoCxC::where('tipo', MovimientoCxC::TIPO_CARGO_INICIAL)->firstOrFail();

    expect(fn () => $mov->update(['monto_centavos' => 1]))->toThrow(DomainException::class);
});

it('MovimientoCxC no puede eliminarse vía Eloquent', function () {
    $cuenta = mxcCuenta();
    $mov = MovimientoCxC::where('tipo', MovimientoCxC::TIPO_CARGO_INICIAL)->firstOrFail();

    expect(fn () => $mov->delete())->toThrow(DomainException::class);
});

it('UPDATE directo SQL falla por trigger append-only', function () {
    $cuenta = mxcCuenta();
    $mov = MovimientoCxC::where('tipo', MovimientoCxC::TIPO_CARGO_INICIAL)->firstOrFail();

    expect(fn () => DB::table('movimientos_cxc')->where('id', $mov->id)->update(['monto_centavos' => 1]))
        ->toThrow(QueryException::class);
});

it('DELETE directo SQL falla por trigger append-only', function () {
    $cuenta = mxcCuenta();
    $mov = MovimientoCxC::where('tipo', MovimientoCxC::TIPO_CARGO_INICIAL)->firstOrFail();

    expect(fn () => DB::table('movimientos_cxc')->where('id', $mov->id)->delete())
        ->toThrow(QueryException::class);
});

it('created_at existe y es datetime', function () {
    $cuenta = mxcCuenta();
    $mov = MovimientoCxC::where('tipo', MovimientoCxC::TIPO_CARGO_INICIAL)->firstOrFail();

    expect($mov->created_at)->not->toBeNull();
    expect($mov->created_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('la tabla movimientos_cxc NO tiene updated_at', function () {
    $columnas = DB::select("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_name = 'movimientos_cxc'
    ");

    $nombres = collect($columnas)->pluck('column_name')->all();

    expect($nombres)->toContain('created_at');
    expect($nombres)->not->toContain('updated_at');
});

it('RefreshDatabase recrea constraints y triggers correctamente', function () {
    $tablas = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name IN ('cuentas_por_cobrar','movimientos_cxc')");

    expect(collect($tablas)->pluck('table_name'))->toContain('cuentas_por_cobrar');
    expect(collect($tablas)->pluck('table_name'))->toContain('movimientos_cxc');

    $triggers = DB::select("SELECT tgname FROM pg_trigger WHERE NOT tgisinternal AND tgrelid IN ('cuentas_por_cobrar'::regclass, 'movimientos_cxc'::regclass)");

    $nombres = collect($triggers)->pluck('tgname')->all();

    expect($nombres)->toContain('cxc_proteger_historial');
    expect($nombres)->toContain('cxc_proteger_delete');
    expect($nombres)->toContain('mxc_append_only');
    expect($nombres)->toContain('mxc_reversa_valida_origen');
});
