<?php

namespace App\Support;

/**
 * Formateo de líneas para el ticket térmico como texto monoespaciado con
 * relleno real (espacios ASCII), en vez de depender de CSS (flexbox) para
 * separar visualmente una etiqueta de su valor.
 *
 * Motivo (bug reportado 08-sep-2026): en ciertos controladores/drivers de
 * impresoras térmicas (por ejemplo, un driver "Genérico / Solo texto" de
 * Windows, muy común en impresoras térmicas USB baratas), el navegador
 * imprime aplanando el HTML a texto plano y NO respeta el layout CSS
 * (flexbox, `justify-content: space-between`, anchos en mm). El resultado:
 * la etiqueta y el valor de cada línea quedan pegados sin espacio
 * ("FolioVTA-000001"), y cualquier carácter no-ASCII decorativo que
 * usábamos (el separador "·", los "&nbsp;" de sangría) se traduce mal a un
 * carácter irreconocible porque ese driver usa una página de códigos
 * heredada (tipo CP437/CP850) en vez de UTF-8.
 *
 * La corrección: construir el ticket como texto monoespaciado real, con
 * espacios ASCII auténticos calculados aquí (no espacios "visuales" de
 * CSS), envuelto en un bloque <pre>. Así se ve igual de bien tanto si el
 * driver imprime el HTML respetando el CSS como si lo aplana a texto
 * plano — porque en ambos casos el contenido de texto ya trae el
 * espaciado correcto.
 */
final class TicketTexto
{
    /** Columnas de texto según el ancho físico del ticket (mm). */
    public const COLUMNAS = [
        58 => 32,
        80 => 46,
    ];

    public static function columnas(int $anchoMm): int
    {
        return self::COLUMNAS[$anchoMm] ?? self::COLUMNAS[80];
    }

    /**
     * Une una etiqueta a la izquierda y un valor a la derecha en una sola
     * línea de $cols caracteres, rellenando con espacios ASCII reales. Si
     * no caben en una línea, el valor se recorre a una segunda línea
     * (indentada), en vez de truncar información.
     *
     * @return string[] una o dos líneas ya formateadas
     */
    public static function linea(string $izquierda, string $derecha, int $cols): array
    {
        $izquierda = self::aAscii($izquierda);
        $derecha = self::aAscii($derecha);

        $largoIzq = mb_strlen($izquierda);
        $largoDer = mb_strlen($derecha);

        if ($largoIzq + 1 + $largoDer <= $cols) {
            $espacios = $cols - $largoIzq - $largoDer;

            return [$izquierda.str_repeat(' ', max(1, $espacios)).$derecha];
        }

        // No caben en una sola línea: la etiqueta va en su propia línea y
        // el valor queda alineado a la derecha en la línea siguiente.
        $relleno = max(0, $cols - $largoDer);

        return [$izquierda, str_repeat(' ', $relleno).$derecha];
    }

    /** Centra un texto dentro de $cols caracteres. */
    public static function centrado(string $texto, int $cols): string
    {
        $texto = self::aAscii($texto);
        $largo = mb_strlen($texto);

        if ($largo >= $cols) {
            return $texto;
        }

        $totalRelleno = $cols - $largo;
        $izq = intdiv($totalRelleno, 2);
        $der = $totalRelleno - $izq;

        return str_repeat(' ', $izq).$texto.str_repeat(' ', $der);
    }

    /** Línea separadora completa (guiones ASCII, nunca líneas Unicode). */
    public static function separador(int $cols, string $caracter = '-'): string
    {
        return str_repeat($caracter, $cols);
    }

    /**
     * Envuelve un texto libre (notas, direcciones largas) a $cols
     * caracteres por línea, sin cortar palabras a la mitad.
     *
     * @return string[]
     */
    public static function envolver(string $texto, int $cols): array
    {
        $texto = self::aAscii($texto);

        return explode("\n", wordwrap($texto, $cols, "\n", true));
    }

    /**
     * Sustituye los únicos caracteres decorativos no-ASCII que el propio
     * sistema agrega (separador "·", nbsp de sangría) por equivalentes
     * ASCII seguros. Los acentos que vienen de datos reales (nombres,
     * domicilios) NO se tocan aquí — son datos del negocio, no adorno, y
     * la mayoría de impresoras térmicas sí manejan letras acentuadas
     * comunes correctamente; solo los símbolos "decorativos" que
     * agregábamos nosotros mismos eran el problema real.
     */
    private static function aAscii(string $texto): string
    {
        return str_replace(
            ["\u{00B7}", "\u{00A0}", '—', '–'],
            ['-', ' ', '-', '-'],
            $texto
        );
    }
}
