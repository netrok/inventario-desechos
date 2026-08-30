<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Configuración general del sistema (tabla singleton, un solo registro).
 *
 * NO contiene secretos: sólo datos de identidad mostrados en tickets y
 * preferencias operativas (ancho de ticket, autoprint, pie de ticket).
 */
class Configuracion extends Model
{
    use LogsActivity;

    protected $table = 'configuracion';

    protected $fillable = [
        'empresa_nombre',
        'empresa_rfc',
        'empresa_telefono',
        'empresa_email',
        'empresa_direccion',
        'ticket_pie',
        'ticket_ancho',
        'ticket_autoprint',
    ];

    protected $casts = [
        'ticket_ancho' => 'integer',
        'ticket_autoprint' => 'boolean',
    ];

    public const ANCHOS_VALIDOS = [58, 80];

    public const CACHE_KEY = 'configuracion.general';

    /**
     * Devuelve la configuración activa (crea la fila singleton si no existe).
     * Resultado cacheado; se invalida al guardar.
     *
     * La unicidad está garantizada de forma determinista por el índice único
     * `configuracion_singleton` (ver migración): aunque dos peticiones
     * concurrentes intenten crear a la vez, sólo una fila puede existir;
     * la que pierde la carrera re-lee la fila existente en lugar de fallar.
     */
    public static function obtener(): self
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            $fila = static::query()->orderBy('id')->first();

            if ($fila) {
                return $fila;
            }

            try {
                return static::create([
                    'empresa_nombre' => config('app.name', 'Inventario ReUse'),
                    'ticket_ancho' => 80,
                    'ticket_autoprint' => false,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Carrera concurrente: otra petición ya creó la fila singleton.
                // Re-leer la existente (garantizado por el índice único).
                $fila = static::query()->orderBy('id')->first();

                if ($fila) {
                    return $fila;
                }

                throw $e;
            }
        });
    }

    /**
     * Comportamiento seguro si todavía no existe fila: valores por defecto.
     */
    public static function ticketAncho(): int
    {
        try {
            $ancho = (int) static::obtener()->ticket_ancho;
        } catch (\Throwable) {
            $ancho = 80;
        }

        return in_array($ancho, self::ANCHOS_VALIDOS, true) ? $ancho : 80;
    }

    public static function ticketAutoprint(): bool
    {
        try {
            return (bool) static::obtener()->ticket_autoprint;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function ticketPie(): ?string
    {
        try {
            $pie = static::obtener()->ticket_pie;
        } catch (\Throwable) {
            return null;
        }

        return is_string($pie) && trim($pie) !== '' ? trim($pie) : null;
    }

    protected static function booted(): void
    {
        static::saved(function () {
            Cache::forget(self::CACHE_KEY);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'empresa_nombre',
                'empresa_rfc',
                'empresa_telefono',
                'empresa_email',
                'empresa_direccion',
                'ticket_pie',
                'ticket_ancho',
                'ticket_autoprint',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Configuración general {$eventName}");
    }
}
