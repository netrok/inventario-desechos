<?php

namespace App\Support;

/**
 * Normalización de lectura para códigos de Item (escáner QR / teclado).
 *
 * REUSE fix — el lector QR entrega `ITM'000008` cuando el código persitido es
 * `ITM-000008` (el apóstrofe también se reproduce en Windows). Este helper
 * tolera ese error de lectura sin cambiar la generación del código, la
 * sequence, el `Item::codigo` persistido ni el QR generado.
 *
 * Solo transforma el valor si completa una lectura plausible de código Item:
 * `ITM` + separador equivalente + exactamente 6 dígitos. En cualquier otro
 * caso devuelve únicamente trim + uppercase, sin inventar un código ni
 * reemplazar apóstrofes en strings arbitrarios.
 */
final class ItemCodigo
{
    public static function normalizarLectura(?string $valor): string
    {
        $base = mb_strtoupper(trim((string) $valor));

        // Se quitan espacios alrededor/intermedios SOLO para códigos Item:
        // el patrón siguiente solo puede matchear una lectura ITM.
        $compacto = preg_replace('/\s+/u', '', $base);

        // Separadores equivalentes de lectura: - ' ’ ‘ ´ `
        if (preg_match("/^ITM([\-'\u{2019}\u{2018}\u{00B4}`])(\d{6})$/u", $compacto, $m)) {
            return 'ITM-'.$m[2];
        }

        return $base;
    }
}
