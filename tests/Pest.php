<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Helpers B14: caja y pagos para el POS bajo el nuevo contrato.
 */
function openCajaFor(\App\Models\User $user, float $fondo = 0.0): \App\Models\SesionCaja
{
    $existente = \App\Models\SesionCaja::query()
        ->where('user_id_apertura', $user->id)
        ->abiertas()
        ->first();

    if ($existente) {
        return $existente;
    }

    $ca = \App\Models\Caja::create([
        'nombre' => 'Caja de pruebas '.$user->id.(string) uniqid('', false),
        'activa' => true,
        'descripcion' => 'Caja de pruebas.',
        // B14.3.1 FIX 3: la caja ACTIVA exige operador (CHECK). Se asigna el
        // propio usuario que la abre.
        'usuario_asignado_id' => $user->id,
    ]);

    return \App\Models\SesionCaja::create([
        'caja_id' => $ca->id,
        'user_id_apertura' => $user->id,
        'fondo_inicial' => number_format($fondo, 2, '.', ''),
        'estado' => \App\Models\SesionCaja::ESTADO_ABIERTA,
    ]);
}

function pagosEfectivo(float $total, float $recibido = 0.0): array
{
    $r = $recibido > 0 ? $recibido : $total;

    return ['pagos' => [[
        'metodo' => \App\Models\PagoVenta::METODO_EFECTIVO,
        'monto_aplicado' => number_format($total, 2, '.', ''),
        'efectivo_recibido' => number_format($r, 2, '.', ''),
    ]]];
}

function pagosMetodo(float $total, string $metodo, ?string $referencia = null): array
{
    return ['pagos' => [[
        'metodo' => $metodo,
        'monto_aplicado' => number_format($total, 2, '.', ''),
        'referencia' => $referencia,
    ]]];
}

function pagosMixtos(float $total, float $efectivo, string $segundoMetodo, float $segundo): array
{
    return ['pagos' => [
        [
            'metodo' => \App\Models\PagoVenta::METODO_EFECTIVO,
            'monto_aplicado' => number_format($efectivo, 2, '.', ''),
            'efectivo_recibido' => number_format($efectivo, 2, '.', ''),
        ],
        [
            'metodo' => $segundoMetodo,
            'monto_aplicado' => number_format($segundo, 2, '.', ''),
            'referencia' => 'TRX-'.rand(1000, 9999),
        ],
    ]];
}
