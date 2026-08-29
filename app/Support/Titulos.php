<?php

namespace App\Support;

/**
 * Composición centralizada de títulos de página: "Sección | Inventario ReUse".
 * Garantiza que NUNCA aparezca "Laravel" como fallback visible en el <title>.
 */
final class Titulos
{
    /**
     * Devuelve el nombre de la aplicación (con fallback seguro).
     */
    public static function app(): string
    {
        $name = (string) config('app.name', 'Inventario ReUse');

        return trim($name) !== '' && strtolower($name) !== 'laravel'
            ? $name
            : 'Inventario ReUse';
    }

    /**
     * "Sección | Inventario ReUse". Si $seccion es nulo/vacío, solo el nombre de la app.
     */
    public static function componer(?string $seccion = null): string
    {
        $app = self::app();
        $seccion = trim((string) $seccion);

        return $seccion === '' ? $app : "{$seccion} | {$app}";
    }
}
