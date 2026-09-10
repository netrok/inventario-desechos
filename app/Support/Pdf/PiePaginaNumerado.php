<?php

namespace App\Support\Pdf;

use Barryvdh\DomPDF\PDF as PdfWrapper;

/**
 * Escribe "Página X de Y" en el pie de cada hoja de un PDF generado con
 * dompdf (barryvdh/laravel-dompdf).
 *
 * Por qué existe esta clase (bug 10-sep-2026): los reportes en PDF traían
 * literalmente el texto "{PAGE_NUM}" y "{PAGE_COUNT}" sin reemplazar en el
 * pie de página. La sustitución automática de esos tokens dentro de texto
 * HTML normal está DESHABILITADA en el propio código de dompdf (queda como
 * comentario muerto en Dompdf\Renderer\Text::render() desde hace varias
 * versiones) — por eso nunca iba a funcionar sin importar cómo se escribiera
 * el Blade.
 *
 * El único mecanismo de dompdf que sí reemplaza esos tokens es
 * Canvas::page_text(), que dibuja texto fijo en cada página del PDF ya
 * renderizado — pero solo se puede invocar desde PHP (no desde el Blade),
 * después de $pdf->render() y antes de generar la salida final
 * (->output()/->download()/->stream()).
 *
 * Uso en el controller, en vez de ->download()/->stream() directo:
 *
 *   $pdf = Pdf::loadView('items.pdf', [...])->setPaper('a4', 'landscape')->setOptions([...]);
 *   PiePaginaNumerado::escribir($pdf);
 *   return $pdf->download('items.pdf');
 */
class PiePaginaNumerado
{
    /**
     * @param  float[]  $color  RGB normalizado 0-1, ej. [0.42, 0.45, 0.5] ~= #6B7280
     */
    public static function escribir(
        PdfWrapper $pdf,
        float $margenDerechoPt = 18.0,
        float $margenInferiorPt = 16.0,
        float $tamanoFuente = 9.0,
        array $color = [0.42, 0.45, 0.5],
    ): void {
        // page_text() solo tiene efecto sobre páginas ya construidas: hay
        // que forzar el render aqui (si no, ->output() lo haria despues sin
        // el texto, porque el canvas para entonces ya se congelo).
        $pdf->render();

        $canvas = $pdf->getCanvas();
        $fontMetrics = $pdf->getFontMetrics();
        $font = $fontMetrics->getFont('DejaVu Sans');

        $texto = 'Página {PAGE_NUM} de {PAGE_COUNT}';
        $anchoTexto = $fontMetrics->getTextWidth($texto, $font, $tamanoFuente);

        $x = $canvas->get_width() - $margenDerechoPt - $anchoTexto;
        $y = $canvas->get_height() - $margenInferiorPt;

        $canvas->page_text($x, $y, $texto, $font, $tamanoFuente, $color);
    }
}
