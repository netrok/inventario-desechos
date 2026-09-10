<?php

namespace App\Support\Pdf;

/**
 * Recorta al centro y redimensiona una foto a un cuadrado exacto, lista
 * para insertarse como data: URI en un PDF generado con dompdf.
 *
 * Por qué existe (bug 10-sep-2026): el reporte de Items insertaba la foto
 * original del artículo tal cual (cualquier tamaño/proporción que tuviera,
 * tomada con el celular) y confiaba en que el CSS (width:42px;height:42px)
 * la ajustara. dompdf no siempre respeta esas medidas cuando la imagen
 * fuente no coincide — el resultado era una foto chiquita y pegada a la
 * izquierda de su celda en vez de llenarla. La única forma confiable es
 * entregarle a dompdf una imagen que YA mida exactamente lo que se va a
 * mostrar, recortada en cuadro (no estirada) desde el centro.
 */
class MiniaturaImagen
{
    public static function paraPdf(?string $rutaRelativaStorage, int $ladoPx = 84): ?string
    {
        if (empty($rutaRelativaStorage)) {
            return null;
        }

        $full = public_path('storage/'.ltrim($rutaRelativaStorage, '/'));
        if (! is_file($full)) {
            return null;
        }

        $info = @getimagesize($full);
        if (! $info) {
            return null;
        }

        [$anchoOriginal, $altoOriginal, $tipo] = $info;

        $origen = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($full),
            IMAGETYPE_PNG => @imagecreatefrompng($full),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($full) : null,
            default => null,
        };

        if (! $origen) {
            return null;
        }

        // Recorte centrado cuadrado ("cover"): ni estira la foto ni la dej
        // más chica de lo que debería, sin importar la proporción original.
        $lado = min($anchoOriginal, $altoOriginal);
        $origenX = (int) (($anchoOriginal - $lado) / 2);
        $origenY = (int) (($altoOriginal - $lado) / 2);

        $miniatura = imagecreatetruecolor($ladoPx, $ladoPx);
        $blanco = imagecolorallocate($miniatura, 255, 255, 255);
        imagefill($miniatura, 0, 0, $blanco);

        imagecopyresampled(
            $miniatura, $origen,
            0, 0, $origenX, $origenY,
            $ladoPx, $ladoPx, $lado, $lado,
        );
        imagedestroy($origen);

        ob_start();
        imagejpeg($miniatura, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($miniatura);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($bytes);
    }
}
