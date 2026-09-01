<?php

use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\MovimientoCxC;
use App\Models\User;
use App\Models\Venta;
use App\Services\CuentaPorCobrarService;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Cliente con crédito habilitado y configuración válida.
 */
function clienteConCredito(
    bool $habilitado = true,
    string $limite = '1000.00',
    ?int $dias = 30
): Cliente {
    return Cliente::create([
        'nombre' => 'Cliente Crédito',
        'credito_habilitado' => $habilitado,
        'limite_credito' => $limite,
        'dias_credito' => $dias,
    ]);
}

/**
 * Venta con cliente (crédito financiable).
 */
function ventaConCliente(
    Cliente $cliente,
    string $total = '1000.00',
    ?string $createdAt = null,
    ?string $estado = null
): Venta {
    $user = User::factory()->create();

    $data = [
        'user_id' => $user->id,
        'cliente_id' => $cliente->id,
        'total' => $total,
        'forma_pago' => 'EFECTIVO',
    ];

    if ($estado !== null) {
        $data['estado'] = $estado;
    }

    $venta = Venta::create($data);

    if ($createdAt !== null) {
        DB::table('ventas')->where('id', $venta->id)->update(['created_at' => $createdAt]);
        $venta = $venta->fresh();
    }

    return $venta;
}

it('crearParaVenta crea una CxC PENDIENTE con folio CXC', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    expect($cuenta->estado)->toBe(CuentaPorCobrar::ESTADO_PENDIENTE);
    expect($cuenta->folio)->toMatch('/^CXC-\d{6}$/');
    expect($cuenta->venta_id)->toBe($venta->id);
    expect($cuenta->cliente_id)->toBe($cliente->id);
});

it('importe_original_centavos y saldo iguales al financiado', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    expect($cuenta->importe_original_centavos)->toBe(60000);
    expect($cuenta->saldo_centavos)->toBe(60000);
});

it('venta 1000.00 con financiado 60000 centavos crea principal 600.00 no 1000.00', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    $cuenta = CuentaPorCobrar::sole();
    expect($cuenta->importe_original_centavos)->toBe(60000);
    expect(Money::aPrecio($cuenta->importe_original_centavos))->toBe('600.00');
    expect(Money::aPrecio($cuenta->importe_original_centavos))->not->toBe('1000.00');
    // No se creó ningún PagoVenta: crédito es deuda, no dinero.
    expect(\App\Models\PagoVenta::count())->toBe(0);
});

it('crea exactamente un CARGO_INICIAL', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    app(CuentaPorCobrarService::class)->crearParaVenta($venta, 50000, $actor);

    $mov = MovimientoCxC::where('tipo', MovimientoCxC::TIPO_CARGO_INICIAL)->sole();
    expect($mov->saldo_antes_centavos)->toBe(0);
    expect($mov->saldo_despues_centavos)->toBe(50000);
    expect($mov->monto_centavos)->toBe(50000);
    expect($mov->metodo)->toBeNull();
    expect($mov->movimiento_origen_id)->toBeNull();
    expect($mov->user_id)->toBe($actor->id);
});

it('folios CXC consecutivos', function () {
    $cliente = clienteConCredito();
    $v1 = ventaConCliente($cliente, '100.00');
    $v2 = ventaConCliente($cliente, '200.00');
    $actor = User::factory()->create();

    $a = app(CuentaPorCobrarService::class)->crearParaVenta($v1, 10000, $actor);
    $b = app(CuentaPorCobrarService::class)->crearParaVenta($v2, 20000, $actor);

    expect((int) substr($a->folio, 4))->toBeLessThan((int) substr($b->folio, 4));
    expect($a->folio)->toMatch('/^CXC-\d{6}$/');
});

it('folio no usa MAX()+1', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '100.00');
    $actor = User::factory()->create();

    app(CuentaPorCobrarService::class)->crearParaVenta($venta, 10000, $actor);

    $seq = DB::selectOne('SELECT last_value, is_called FROM cxc_folio_seq_generator');

    // El folio asignado corresponde al nextval de la sequence, no al MAX(folio).
    expect($seq->is_called)->toBeTrue();
    $cuenta = CuentaPorCobrar::sole();
    expect((int) substr($cuenta->folio, 4))->toBe((int) $seq->last_value);
});

it('fecha_vencimiento usa Venta.created_at + dias, no now()', function () {
    $cliente = clienteConCredito(dias: 30);
    $venta = ventaConCliente($cliente, '500.00', '2026-08-01 10:00:00');
    $actor = User::factory()->create();

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($venta, 30000, $actor);

    expect($cuenta->fecha_vencimiento->toDateString())->toBe('2026-08-31');
    expect($cuenta->dias_credito_aplicados)->toBe(30);
});

it('cambiar Cliente.dias_credito después no cambia snapshot ni vencimiento', function () {
    $cliente = clienteConCredito(dias: 30);
    $venta = ventaConCliente($cliente, '500.00', '2026-08-01 10:00:00');
    $actor = User::factory()->create();

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($venta, 30000, $actor);

    $cliente->update(['dias_credito' => 60]);
    $cuenta->refresh();

    expect($cuenta->dias_credito_aplicados)->toBe(30);
    expect($cuenta->fecha_vencimiento->toDateString())->toBe('2026-08-31');
});

it('rechaza importe menor o igual a cero', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '100.00');
    $actor = User::factory()->create();

    expect(fn () => app(CuentaPorCobrarService::class)->crearParaVenta($venta, 0, $actor))
        ->toThrow(DomainException::class);

    expect(fn () => app(CuentaPorCobrarService::class)->crearParaVenta($venta, -100, $actor))
        ->toThrow(DomainException::class);
});

it('rechaza importe mayor al total de la venta', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '100.00');
    $actor = User::factory()->create();

    expect(fn () => app(CuentaPorCobrarService::class)->crearParaVenta($venta, 10001, $actor))
        ->toThrow(DomainException::class);
});

it('rechaza cliente sin crédito habilitado', function () {
    $cliente = clienteConCredito(habilitado: false);
    $venta = ventaConCliente($cliente, '100.00');
    $actor = User::factory()->create();

    expect(fn () => app(CuentaPorCobrarService::class)->crearParaVenta($venta, 5000, $actor))
        ->toThrow(DomainException::class);
});

it('la BD impide cliente con crédito habilitado pero límite cero', function () {
    // B15.1: clientes_credito_habilitado_requisitos prohíbe límite <= 0 con
    // crédito habilitado. Por tanto el servicio nunca ve un cliente así
    // (defensa en profundidad del guard no es alcanzable por estado legal).
    expect(fn () => Cliente::create([
        'nombre' => 'Invalido',
        'credito_habilitado' => true,
        'limite_credito' => '0.00',
        'dias_credito' => 30,
    ]))->toThrow(QueryException::class);
});

it('la BD impide cliente con crédito habilitado pero sin días de crédito', function () {
    expect(fn () => Cliente::create([
        'nombre' => 'Invalido',
        'credito_habilitado' => true,
        'limite_credito' => '1000.00',
        'dias_credito' => null,
    ]))->toThrow(QueryException::class);
});

it('rechaza venta sin cliente', function () {
    $user = User::factory()->create();
    $venta = Venta::create(['user_id' => $user->id, 'total' => '100.00', 'forma_pago' => 'EFECTIVO']);
    $actor = User::factory()->create();

    expect(fn () => app(CuentaPorCobrarService::class)->crearParaVenta($venta, 5000, $actor))
        ->toThrow(DomainException::class);
});

it('VENTA ACTIVA sin postventa puede financiarse', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00', estado: Venta::ESTADO_ACTIVA);
    $actor = User::factory()->create();

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    expect($cuenta->folio)->toMatch('/^CXC-\d{6}$/');
});

it('rechaza originar CxC desde una venta PARCIALMENTE_DEVUELTA', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00', estado: Venta::ESTADO_PARCIALMENTE_DEVUELTA);
    $actor = User::factory()->create();

    expect(fn () => app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor))
        ->toThrow(DomainException::class, 'La cuenta por cobrar solo puede originarse desde una venta activa.');

    expect(CuentaPorCobrar::count())->toBe(0);
});

it('rechaza originar CxC desde una venta DEVUELTA', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00', estado: Venta::ESTADO_DEVUELTA);
    $actor = User::factory()->create();

    expect(fn () => app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor))
        ->toThrow(DomainException::class, 'La cuenta por cobrar solo puede originarse desde una venta activa.');

    expect(CuentaPorCobrar::count())->toBe(0);
});

it('rechaza originar CxC desde una venta CANCELADA', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00', estado: Venta::ESTADO_CANCELADA);
    $actor = User::factory()->create();

    expect(fn () => app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor))
        ->toThrow(DomainException::class, 'La cuenta por cobrar solo puede originarse desde una venta activa.');

    expect(CuentaPorCobrar::count())->toBe(0);
});

it('rechaza originar CxC sobre una venta ACTIVA con operación postventa', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00', estado: Venta::ESTADO_ACTIVA);
    $actor = User::factory()->create();

    \App\Models\DocumentoPostventa::create([
        'venta_id' => $venta->id,
        'user_id' => $actor->id,
        'tipo' => 'CANCELACION',
        'motivo' => 'Reversa de prueba.',
        'forma_reembolso' => 'EFECTIVO',
        'total' => '0.00',
    ]);

    expect(fn () => app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor))
        ->toThrow(DomainException::class, 'No se puede originar crédito sobre una venta con operaciones postventa.');

    expect(CuentaPorCobrar::count())->toBe(0);
});

it('servicio usa la venta releída bajo lock, no el estado stale del objeto', function () {
    $cliente = clienteConCredito();
    // Venta creada como ACTIVA...
    $venta = ventaConCliente($cliente, '1000.00', estado: Venta::ESTADO_ACTIVA);
    $actor = User::factory()->create();

    // ...pero el objeto en memoria dice CANCELADA (stale). El servicio relee la BD.
    $objetoStale = new Venta;
    $objetoStale->id = $venta->id;
    $objetoStale->estado = Venta::ESTADO_CANCELADA;

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($objetoStale, 60000, $actor);

    expect($cuenta->folio)->toMatch('/^CXC-\d{6}$/');
});

it('rechaza segunda CxC de la misma venta', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    app(CuentaPorCobrarService::class)->crearParaVenta($venta, 50000, $actor);

    expect(fn () => app(CuentaPorCobrarService::class)->crearParaVenta($venta, 50000, $actor))
        ->toThrow(DomainException::class);

    expect(CuentaPorCobrar::count())->toBe(1);
});

it('acepta financiamiento dentro del límite considerando exposición previa', function () {
    $cliente = clienteConCredito(limite: '1000.00');
    $v1 = ventaConCliente($cliente, '600.00');
    $v2 = ventaConCliente($cliente, '400.00');
    $actor = User::factory()->create();

    app(CuentaPorCobrarService::class)->crearParaVenta($v1, 60000, $actor);

    // 600 + 400 = 1000 <= 1000 -> acepta
    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($v2, 40000, $actor);
    expect($cuenta->saldo_centavos)->toBe(40000);
});

it('rechaza financiamiento que excede el límite con exposición previa', function () {
    $cliente = clienteConCredito(limite: '1000.00');
    $v1 = ventaConCliente($cliente, '600.00');
    $v2 = ventaConCliente($cliente, '500.00');
    $actor = User::factory()->create();

    app(CuentaPorCobrarService::class)->crearParaVenta($v1, 60000, $actor);

    // 600 + 500 = 1100 > 1000 -> rechaza
    expect(fn () => app(CuentaPorCobrarService::class)->crearParaVenta($v2, 50000, $actor))
        ->toThrow(DomainException::class);

    expect(CuentaPorCobrar::count())->toBe(1);
});

it('cuentas SALDADA/CANCELADA con saldo cero no suman exposición', function () {
    $cliente = clienteConCredito(limite: '1000.00');
    $actor = User::factory()->create();

    $v1 = ventaConCliente($cliente, '500.00');
    $c1 = app(CuentaPorCobrarService::class)->crearParaVenta($v1, 50000, $actor);

    // Saldar una y cancelar otra: ambas saldo 0 -> no suman exposición.
    DB::table('cuentas_por_cobrar')->where('id', $c1->id)->update([
        'saldo_centavos' => 0,
        'estado' => CuentaPorCobrar::ESTADO_SALDADA,
    ]);

    $v2 = ventaConCliente($cliente, '300.00');
    $c2 = app(CuentaPorCobrarService::class)->crearParaVenta($v2, 30000, $actor);

    DB::table('cuentas_por_cobrar')->where('id', $c2->id)->update([
        'saldo_centavos' => 0,
        'estado' => CuentaPorCobrar::ESTADO_CANCELADA,
        'importe_original_centavos' => 30000,
    ]);

    $v3 = ventaConCliente($cliente, '1000.00');
    // límite 1000 <= 1000 -> acepta (las dos previas no pesan)
    $c3 = app(CuentaPorCobrarService::class)->crearParaVenta($v3, 100000, $actor);
    expect($c3->saldo_centavos)->toBe(100000);
});

it('servicio relee la BD y ignora atributos stale del objeto Venta', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00', '2026-08-01 10:00:00');
    $actor = User::factory()->create();

    // Objeto con atributos falseados (stale/no persistidos), solo id válido.
    $objetoFalsificado = new Venta;
    $objetoFalsificado->id = $venta->id; // setter directo: no es mass-assigned
    $objetoFalsificado->cliente_id = null;
    $objetoFalsificado->total = '999999.00';

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($objetoFalsificado, 60000, $actor);

    expect($cuenta->cliente_id)->toBe($cliente->id);
    expect($cuenta->fecha_vencimiento->toDateString())->toBe('2026-08-31');
});

it('FK compuesta rechaza CxC con cliente distinto al de la venta (SQL)', function () {
    $clienteA = clienteConCredito();
    $clienteB = clienteConCredito();
    $venta = ventaConCliente($clienteA, '1000.00');
    $actor = User::factory()->create();

    // Insert directo con cliente_id != venta.cliente_id -> FK compuesta falla.
    expect(fn () => DB::table('cuentas_por_cobrar')->insertGetId([
        'folio' => 'CXC-999999',
        'venta_id' => $venta->id,
        'cliente_id' => $clienteB->id,
        'importe_original_centavos' => 100000,
        'saldo_centavos' => 100000,
        'dias_credito_aplicados' => 30,
        'fecha_vencimiento' => '2026-09-01',
        'estado' => 'PENDIENTE',
        'created_at' => now(),
        'updated_at' => now(),
    ], 'id'))->toThrow(QueryException::class);
});

it('cambiar venta.cliente_id cuando existe CxC falla por integridad', function () {
    $clienteA = clienteConCredito();
    $clienteB = clienteConCredito();
    $venta = ventaConCliente($clienteA, '1000.00');
    $actor = User::factory()->create();

    app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    expect(fn () => DB::table('ventas')->where('id', $venta->id)->update(['cliente_id' => $clienteB->id]))
        ->toThrow(QueryException::class);
});

it('campos históricos CxC no pueden modificarse por Eloquent', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    expect(fn () => $cuenta->update(['importe_original_centavos' => 1]))
        ->toThrow(DomainException::class);
});

it('campos históricos CxC no pueden modificarse por SQL directo', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    expect(fn () => DB::table('cuentas_por_cobrar')->where('id', $cuenta->id)->update([
        'importe_original_centavos' => 1,
    ]))->toThrow(QueryException::class);
});

it('created_at de CxC es histórico y no puede cambiarse por Eloquent', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    expect(function () use ($cuenta) {
        $cuenta->created_at = $cuenta->created_at->copy()->subDay();
        $cuenta->save();
    })->toThrow(DomainException::class);
});

it('created_at de CxC no puede cambiarse por SQL directo (trigger)', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    expect(fn () => DB::transaction(function () use ($cuenta) {
        DB::table('cuentas_por_cobrar')
            ->where('id', $cuenta->id)
            ->update([
                'created_at' => now()->subDay(),
            ]);
    }))->toThrow(QueryException::class);

    // updated_at sigue siendo operacional: sí se puede modificar por SQL directo.
    $affected = DB::table('cuentas_por_cobrar')
        ->where('id', $cuenta->id)
        ->update([
            'updated_at' => now()->addMinute(),
        ]);

    expect($affected)->toBe(1);
});

it('venta válida ACTIVA pero sin created_at no origina crédito', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    // Persistido sin fecha de origen (regresión: venta recién insertada sin created_at).
    DB::table('ventas')->where('id', $venta->id)->update(['created_at' => null]);

    expect(fn () => app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor))
        ->toThrow(DomainException::class, 'La venta no tiene fecha de origen válida para calcular el vencimiento.');

    expect(CuentaPorCobrar::count())->toBe(0);
    expect(MovimientoCxC::where('tipo', MovimientoCxC::TIPO_CARGO_INICIAL)->count())->toBe(0);
});

it('User actor sin persistir se rechaza de forma controlada', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');

    $noPersistido = new User;
    $noPersistido->name = 'Fantasma';

    expect(fn () => app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $noPersistido))
        ->toThrow(DomainException::class, 'El usuario actor no es válido.');

    expect(CuentaPorCobrar::count())->toBe(0);
    expect(MovimientoCxC::count())->toBe(0);
});

it('User actor persistido continúa normalmente', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    expect($cuenta->exists)->toBeTrue();
    $mov = MovimientoCxC::sole();
    expect($mov->user_id)->toBe($actor->id);
});

it('índice parcial real de PostgreSQL tiene WHERE saldo_centavos > 0', function () {
    $indexdef = DB::selectOne('
        SELECT indexdef
        FROM pg_indexes
        WHERE tablename = \'cuentas_por_cobrar\'
          AND indexname = \'cxc_cliente_saldo_activo_idx\'
    ');

    expect($indexdef)->not->toBeNull();
    expect($indexdef->indexdef)->toContain('saldo_centavos > 0');
    expect($indexdef->indexdef)->toContain('WHERE');
});

it('saldo/estado sí pueden actualizarse en combinación permitida', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    // Estructura válida: PARCIAL con 0 < saldo < original.
    $cuenta->update(['saldo_centavos' => 30000, 'estado' => CuentaPorCobrar::ESTADO_PARCIAL]);
    $cuenta->refresh();

    expect($cuenta->saldo_centavos)->toBe(30000);
    expect($cuenta->estado)->toBe(CuentaPorCobrar::ESTADO_PARCIAL);
});

it('CuentaPorCobrar no puede eliminarse por Eloquent', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    expect(fn () => $cuenta->delete())->toThrow(DomainException::class);
});

it('CuentaPorCobrar no puede eliminarse por SQL directo', function () {
    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    $cuenta = app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    expect(fn () => DB::table('cuentas_por_cobrar')->where('id', $cuenta->id)->delete())
        ->toThrow(QueryException::class);
});

it('estadoNormalDesdeSaldo mapea PENDIENTE, PARCIAL y SALDADA', function () {
    expect(CuentaPorCobrar::estadoNormalDesdeSaldo(10000, 10000))->toBe(CuentaPorCobrar::ESTADO_PENDIENTE);
    expect(CuentaPorCobrar::estadoNormalDesdeSaldo(10000, 6000))->toBe(CuentaPorCobrar::ESTADO_PARCIAL);
    expect(CuentaPorCobrar::estadoNormalDesdeSaldo(10000, 0))->toBe(CuentaPorCobrar::ESTADO_SALDADA);
});

it('estadoNormalDesdeSaldo nunca devuelve CANCELADA', function () {
    expect(CuentaPorCobrar::estadoNormalDesdeSaldo(10000, 10000))->not->toBe(CuentaPorCobrar::ESTADO_CANCELADA);
    expect(CuentaPorCobrar::estadoNormalDesdeSaldo(10000, 0))->not->toBe(CuentaPorCobrar::ESTADO_CANCELADA);
});

it('estadoNormalDesdeSaldo rechaza precondiciones inválidas', function () {
    expect(fn () => CuentaPorCobrar::estadoNormalDesdeSaldo(0, 0))->toThrow(DomainException::class);
    expect(fn () => CuentaPorCobrar::estadoNormalDesdeSaldo(-1, 0))->toThrow(DomainException::class);
    expect(fn () => CuentaPorCobrar::estadoNormalDesdeSaldo(10000, -1))->toThrow(DomainException::class);
    expect(fn () => CuentaPorCobrar::estadoNormalDesdeSaldo(10000, 10001))->toThrow(DomainException::class);
});

it('User con CARGO_INICIAL no puede eliminarse vía UserController', function () {
    \Spatie\Permission\Models\Permission::findOrCreate('usuarios.eliminar', 'web');
    $role = \Spatie\Permission\Models\Role::findOrCreate('Admin', 'web');
    $role->givePermissionTo('usuarios.eliminar');

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    // Segundo admin para no disparar la regla del último Admin.
    User::factory()->create()->assignRole('Admin');

    $cliente = clienteConCredito();
    $venta = ventaConCliente($cliente, '1000.00');
    $actor = User::factory()->create();

    app(CuentaPorCobrarService::class)->crearParaVenta($venta, 60000, $actor);

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $actor))
        ->assertSessionHas('error', 'No se puede eliminar este usuario porque tiene movimientos CxC registrados.');

    expect(User::find($actor->id))->not->toBeNull();
});

it('User sin historia CxC conserva comportamiento normal de eliminación', function () {
    \Spatie\Permission\Models\Permission::findOrCreate('usuarios.eliminar', 'web');
    $role = \Spatie\Permission\Models\Role::findOrCreate('Admin', 'web');
    $role->givePermissionTo('usuarios.eliminar');

    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    User::factory()->create()->assignRole('Admin');

    $actor = User::factory()->create();

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $actor))
        ->assertRedirect('/admin/users');

    expect(User::find($actor->id))->toBeNull();
});
