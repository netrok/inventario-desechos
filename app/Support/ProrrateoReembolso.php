<?php

namespace App\Support;

use DomainException;

/**
 * B14.2
 *
 * Distribuye un nuevo reembolso sobre los pagos originales de una venta
 * usando exclusivamente centavos enteros.
 *
 * Regla:
 * - Mantiene la proporción acumulada de los pagos originales.
 * - Usa método de mayores restos para repartir centavos indivisibles.
 * - Considera todo lo ya reembolsado previamente.
 * - Nunca permite devolver más que lo originalmente cobrado.
 * - La suma de la nueva distribución SIEMPRE coincide exactamente con
 *   el nuevo importe solicitado.
 */
final class ProrrateoReembolso
{
    /**
     * @param  array<int, array{id:int, monto:int, orden:int}>  $pagos
     * @param  array<int, int>  $yaReembolsado  Centavos por pago_id.
     * @return array<int, int> Centavos NUEVOS a devolver por pago_id.
     */
    public static function calcular(
        array $pagos,
        array $yaReembolsado,
        int $nuevoImporte
    ): array {
        if ($pagos === []) {
            throw new DomainException(
                'No existen pagos originales para calcular el reembolso.'
            );
        }

        if ($nuevoImporte <= 0) {
            throw new DomainException(
                'El importe del reembolso debe ser mayor a cero.'
            );
        }

        $normalizados = [];
        $totalOriginal = 0;
        $totalYaReembolsado = 0;

        foreach ($pagos as $pago) {
            $id = (int) ($pago['id'] ?? 0);
            $monto = (int) ($pago['monto'] ?? 0);
            $orden = (int) ($pago['orden'] ?? 0);

            if ($id <= 0 || $monto <= 0) {
                throw new DomainException(
                    'Los pagos originales contienen información inválida.'
                );
            }

            if (isset($normalizados[$id])) {
                throw new DomainException(
                    'Existe un pago original duplicado en el prorrateo.'
                );
            }

            $devuelto = (int) ($yaReembolsado[$id] ?? 0);

            if ($devuelto < 0 || $devuelto > $monto) {
                throw new DomainException(
                    "El pago {$id} tiene un saldo reembolsado inconsistente."
                );
            }

            $normalizados[$id] = [
                'id' => $id,
                'monto' => $monto,
                'orden' => $orden,
                'devuelto' => $devuelto,
            ];

            $totalOriginal += $monto;
            $totalYaReembolsado += $devuelto;
        }

        if ($totalYaReembolsado + $nuevoImporte > $totalOriginal) {
            throw new DomainException(
                'El reembolso supera el saldo económico disponible de la venta.'
            );
        }

        /*
         * Trabajamos contra el TOTAL ACUMULADO que debería haberse reembolsado.
         *
         * Esto evita que varios reembolsos parciales vayan acumulando errores
         * independientes de redondeo.
         */
        $objetivoAcumulado = $totalYaReembolsado + $nuevoImporte;

        $deseadoAcumulado = [];
        $restos = [];
        $sumaBases = 0;

        foreach ($normalizados as $id => $pago) {
            /*
             * ideal = objetivoAcumulado * pagoOriginal / totalOriginal
             *
             * No usamos float:
             * - base = división entera
             * - resto = residuo exacto
             */
            $numerador = $objetivoAcumulado * $pago['monto'];

            $base = intdiv($numerador, $totalOriginal);
            $resto = $numerador % $totalOriginal;

            $deseadoAcumulado[$id] = $base;

            $restos[] = [
                'id' => $id,
                'resto' => $resto,
                'orden' => $pago['orden'],
            ];

            $sumaBases += $base;
        }

        /*
         * Por los pisos anteriores faltarán como máximo N-1 centavos.
         * Se entregan a los pagos con mayor parte fraccionaria.
         *
         * Empates:
         * 1. orden original de PagoVenta
         * 2. id
         *
         * Esto hace el resultado totalmente determinista.
         */
        $centavosPendientes = $objetivoAcumulado - $sumaBases;

        usort($restos, static function (array $a, array $b): int {
            if ($a['resto'] !== $b['resto']) {
                return $b['resto'] <=> $a['resto'];
            }

            if ($a['orden'] !== $b['orden']) {
                return $a['orden'] <=> $b['orden'];
            }

            return $a['id'] <=> $b['id'];
        });

        for ($i = 0; $i < $centavosPendientes; $i++) {
            $id = $restos[$i]['id'];
            $deseadoAcumulado[$id]++;
        }

        /*
         * Convertimos el objetivo acumulado en lo que corresponde devolver
         * SOLAMENTE en esta operación.
         */
        $nuevoPorPago = [];
        $sumaNueva = 0;

        foreach ($normalizados as $id => $pago) {
            $deseado = $deseadoAcumulado[$id];

            if ($deseado > $pago['monto']) {
                throw new DomainException(
                    "El cálculo excede el monto original del pago {$id}."
                );
            }

            $nuevo = $deseado - $pago['devuelto'];

            /*
             * Si esto fuera negativo significaría que el historial previo fue
             * generado con otra distribución incompatible. Nunca intentamos
             * corregirlo silenciosamente.
             */
            if ($nuevo < 0) {
                throw new DomainException(
                    'El historial previo de reembolsos no es compatible con el prorrateo automático.'
                );
            }

            if ($nuevo > 0) {
                $nuevoPorPago[$id] = $nuevo;
                $sumaNueva += $nuevo;
            }
        }

        if ($sumaNueva !== $nuevoImporte) {
            throw new DomainException(
                'El prorrateo no pudo reconciliar exactamente el importe del reembolso.'
            );
        }

        return $nuevoPorPago;
    }
}
