<?php

namespace App\Support;

/**
 * Aritmética monetaria sin float.
 *
 * La política del proyecto (heredada del POS) es:
 *  - PostgreSQL numeric/decimal(12,2) en la BD;
 *  - centavos enteros en PHP cuando hay cálculo;
 *  - importes derivados siempre server-side.
 *
 * Estas utilidades son estáticas y compartidas entre el checkout del POS y
 * el módulo postventa (cancelación/devolución).
 */
final class Money
{
    /**
     * Convierte un decimal(12,2) proveniente de la BD a centavos enteros.
     *
     * Acepta "0" → 0, "0.00" → 0, "1" → 100, "1.2" → 120, "1.20" → 120,
     * "19.99" → 1999. Rechaza nulos, vacíos, no numéricos y más de 2 decimales.
     * No usa punto flotante: aritmética monetaria en enteros.
     */
    public static function aCentavos(int|float|string|null $precio): int
    {
        if ($precio === null || (string) $precio === '') {
            throw new \UnexpectedValueException('Precio inválido.');
        }

        $valor = trim((string) $precio);

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $valor)) {
            throw new \UnexpectedValueException('Precio inválido.');
        }

        $negativo = str_starts_with($valor, '-');
        $digitos = $negativo ? substr($valor, 1) : $valor;

        [$enteros, $fraccion] = array_pad(explode('.', $digitos), 2, null);
        $fraccion = $fraccion === null ? '00' : str_pad($fraccion, 2, '0');

        $centavos = ((int) $enteros) * 100 + (int) $fraccion;

        return $negativo ? -$centavos : $centavos;
    }

    /**
     * Convierte centavos enteros a su representación decimal exacta "123.45".
     * Aritmética entera (división y módulo por 100), sin paso por float.
     */
    public static function aPrecio(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $absoluto = abs($centavos);

        return $signo.sprintf('%d.%02d', intdiv($absoluto, 100), $absoluto % 100);
    }

    /**
     * Presentación de un decimal(12,2) ya persistido sin alterar la cantidad:
     * agrega separador de miles por manipulación de string, sin aritmética float.
     */
    public static function formatear(string $decimal): string
    {
        $negativo = str_starts_with($decimal, '-');
        $num = $negativo ? substr($decimal, 1) : $decimal;

        [$enteros, $centavos] = array_pad(explode('.', $num), 2, '00');

        $grupos = [];
        $pos = strlen($enteros);

        while ($pos > 0) {
            $inicio = max(0, $pos - 3);
            $grupos[] = substr($enteros, $inicio, $pos - $inicio);
            $pos = $inicio;
        }

        return ($negativo ? '-' : '').implode(',', array_reverse($grupos)).'.'.$centavos;
    }
}
