<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoCaja extends Model
{
    public const TIPO_COBRO_EFECTIVO = 'COBRO_EFECTIVO';

    public const TIPO_CAMBIO_ENTREGADO = 'CAMBIO_ENTREGADO';

    public const TIPO_ENTRADA_MANUAL = 'ENTRADA_MANUAL';

    public const TIPO_RETIRO = 'RETIRO';

    public const TIPO_REEMBOLSO_EFECTIVO = 'REEMBOLSO_EFECTIVO';

    public const TIPO_AJUSTE = 'AJUSTE';

    public const TIPOS = [
        self::TIPO_COBRO_EFECTIVO,
        self::TIPO_CAMBIO_ENTREGADO,
        self::TIPO_ENTRADA_MANUAL,
        self::TIPO_RETIRO,
        self::TIPO_REEMBOLSO_EFECTIVO,
        self::TIPO_AJUSTE,
    ];

    public const DIR_ENTRADA = 'ENTRADA';

    public const DIR_SALIDA = 'SALIDA';

    public $timestamps = false;

    protected $table = 'movimientos_caja';

    protected $fillable = [
        'sesion_caja_id',
        'user_id',
        'tipo',
        'direccion',
        'monto',
        'venta_id',
        'pago_venta_id',
        'documento_postventa_id',
        'concepto',
        'referencia',
        'created_at',
    ];

    protected $casts = [
        'sesion_caja_id' => 'integer',
        'user_id' => 'integer',
        'monto' => 'decimal:2',
        'venta_id' => 'integer',
        'pago_venta_id' => 'integer',
        'documento_postventa_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Dirección física que le corresponde a cada tipo (norma de dominio).
     * AJUSTE admite ENTRADA o SALIDA según cómo se registre.
     */
    public static function direccionDeTipo(string $tipo, string $ajuste = self::DIR_ENTRADA): string
    {
        return match ($tipo) {
            self::TIPO_COBRO_EFECTIVO, self::TIPO_ENTRADA_MANUAL => self::DIR_ENTRADA,
            self::TIPO_CAMBIO_ENTREGADO, self::TIPO_RETIRO, self::TIPO_REEMBOLSO_EFECTIVO => self::DIR_SALIDA,
            self::TIPO_AJUSTE => $ajuste === self::DIR_SALIDA ? self::DIR_SALIDA : self::DIR_ENTRADA,
            default => throw new \DomainException("Tipo de movimiento desconocido: {$tipo}."),
        };
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(PagoVenta::class, 'pago_venta_id');
    }

    public function esEntrada(): bool
    {
        return $this->direccion === self::DIR_ENTRADA;
    }

    public function esSalida(): bool
    {
        return $this->direccion === self::DIR_SALIDA;
    }
}
