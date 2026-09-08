<?php

use App\Support\ProrrateoReembolso;

it('prorratea 80 20 sin perder centavos', function () {
    $pagos = [
        ['id' => 1, 'monto' => 800, 'orden' => 1],
        ['id' => 2, 'monto' => 200, 'orden' => 2],
    ];

    $resultado = ProrrateoReembolso::calcular(
        $pagos,
        [],
        400
    );

    expect($resultado)->toBe([
        1 => 320,
        2 => 80,
    ]);

    expect(array_sum($resultado))->toBe(400);
});

it('la cancelacion total reconstruye exactamente los pagos originales', function () {
    $pagos = [
        ['id' => 10, 'monto' => 35000, 'orden' => 1],
        ['id' => 11, 'monto' => 60000, 'orden' => 2],
        ['id' => 12, 'monto' => 5000, 'orden' => 3],
    ];

    $resultado = ProrrateoReembolso::calcular(
        $pagos,
        [],
        100000
    );

    expect($resultado)->toBe([
        10 => 35000,
        11 => 60000,
        12 => 5000,
    ]);
});

it('reparte un centavo de forma determinista por mayor resto', function () {
    $pagos = [
        ['id' => 1, 'monto' => 800, 'orden' => 1],
        ['id' => 2, 'monto' => 200, 'orden' => 2],
    ];

    $resultado = ProrrateoReembolso::calcular(
        $pagos,
        [],
        1
    );

    expect($resultado)->toBe([
        1 => 1,
    ]);

    expect(array_sum($resultado))->toBe(1);
});

it('varias devoluciones parciales mantienen la proporcion acumulada', function () {
    $pagos = [
        ['id' => 1, 'monto' => 800, 'orden' => 1],
        ['id' => 2, 'monto' => 200, 'orden' => 2],
    ];

    $primero = ProrrateoReembolso::calcular(
        $pagos,
        [],
        333
    );

    expect(array_sum($primero))->toBe(333);

    $segundo = ProrrateoReembolso::calcular(
        $pagos,
        $primero,
        333
    );

    expect(array_sum($segundo))->toBe(333);

    $acumulado = [
        1 => ($primero[1] ?? 0) + ($segundo[1] ?? 0),
        2 => ($primero[2] ?? 0) + ($segundo[2] ?? 0),
    ];

    expect(array_sum($acumulado))->toBe(666);

    /*
     * 666 × 80% = 532.8
     * 666 × 20% = 133.2
     *
     * Mayor resto → efectivo recibe el centavo residual.
     */
    expect($acumulado)->toBe([
        1 => 533,
        2 => 133,
    ]);
});

it('las devoluciones acumuladas terminan exactamente en los pagos originales', function () {
    $pagos = [
        ['id' => 1, 'monto' => 333, 'orden' => 1],
        ['id' => 2, 'monto' => 333, 'orden' => 2],
        ['id' => 3, 'monto' => 334, 'orden' => 3],
    ];

    $ya = [];

    foreach ([101, 202, 303, 394] as $importe) {
        $nuevo = ProrrateoReembolso::calcular(
            $pagos,
            $ya,
            $importe
        );

        foreach ($nuevo as $id => $monto) {
            $ya[$id] = ($ya[$id] ?? 0) + $monto;
        }
    }

    expect(array_sum($ya))->toBe(1000);

    expect($ya)->toBe([
        1 => 333,
        2 => 333,
        3 => 334,
    ]);
});

it('impide devolver mas dinero del que se cobro originalmente', function () {
    $pagos = [
        ['id' => 1, 'monto' => 800, 'orden' => 1],
        ['id' => 2, 'monto' => 200, 'orden' => 2],
    ];

    expect(fn () => ProrrateoReembolso::calcular(
        $pagos,
        [1 => 400, 2 => 100],
        501
    ))->toThrow(
        DomainException::class,
        'El reembolso supera el saldo económico disponible de la venta.'
    );
});

it('detecta un historial previo incompatible en lugar de corregirlo silenciosamente', function () {
    $pagos = [
        ['id' => 1, 'monto' => 800, 'orden' => 1],
        ['id' => 2, 'monto' => 200, 'orden' => 2],
    ];

    /*
     * Ya se devolvieron 300 al pago minoritario cuando el objetivo acumulado
     * correspondiente jamás podría justificar ese valor.
     */
    expect(fn () => ProrrateoReembolso::calcular(
        $pagos,
        [1 => 0, 2 => 200],
        1
    ))->toThrow(
        DomainException::class,
        'El historial previo de reembolsos no es compatible con el prorrateo automático.'
    );
});
