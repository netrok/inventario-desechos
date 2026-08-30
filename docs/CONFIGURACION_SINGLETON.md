# Configuración general (tabla singleton)

El módulo de configuración (`configuracion`) guarda la identidad de la empresa y
las preferencias del ticket térmico. Contiene **solo datos no sensibles**; nunca
secretos (`APP_KEY`, contraseñas de BD/SMTP, etc.).

## Garantía de una sola fila

Es una tabla **singleton**: debe existir exactamente una fila. La unicidad está
garantizada de forma **determinista a nivel de base de datos**, no sólo por
lógica de aplicación:

- **Índice único `configuracion_singleton`** sobre la constante `(true)`:
  `CREATE UNIQUE INDEX configuracion_singleton ON configuracion ((true))`.
  PostgreSQL sólo permite una fila en toda la tabla.
- **CHECK `configuracion_ticket_ancho_check`**: `ticket_ancho IN (58, 80)`.

Ambos se declaran en la migración `2026_08_29_180200_create_configuracion_table.php`.

### Por qué no basta `firstOrCreate`

Si dos peticiones concurrentes ejecutan `firstOrCreate`/`create` a la vez (cache
fría, o distintas instancias), ambas podrían insertar filas `id=1, id=2, id=3`.
El índice único impide físicamente la segunda inserción.

### Manejo de la carrera en `Configuracion::obtener()`

```php
public static function obtener(): self
{
    return Cache::remember(self::CACHE_KEY, 300, function () {
        $fila = static::query()->orderBy('id')->first();
        if ($fila) return $fila;

        try {
            return static::create([...valores por defecto...]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Otra petición ganó la carrera; re-leer la fila existente.
            $fila = static::query()->orderBy('id')->first();
            if ($fila) return $fila;
            throw $e;
        }
    });
}
```

- `Cache::remember` amortigua las lecturas.
- El índice único garantiza que, aun fallando la creación concurrente, sólo hay
  una fila; el `catch` re-lee y devuelve la única existente.

## Qué se guarda

- Identidad para tickets: `empresa_nombre`, `empresa_rfc`, `empresa_telefono`,
  `empresa_email`, `empresa_direccion`.
- Ticket: `ticket_pie`, `ticket_ancho` (58/80), `ticket_autoprint` (bool).

Permisos: `configuracion.ver` (ver) y `configuracion.editar` (editar; sólo
Admin). Los cambios se auditan con Spatie Activitylog
(`getActivitylogOptions`).
