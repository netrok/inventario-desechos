<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoVenta extends Model
{
    public const METODO_EFECTIVO = 'EFECTIVO';

    public const METODO_TARJETA = 'TARJETA';

    public const METODO_TRANSFERENCIA = 'TRANSFERENCIA';

    public const METODO_CREDITO = 'CREDITO'; // reservado para B15 (no activo)

    /**
     * Métodos operacionales habilitados en B14. CREDITO NO está entre ellos:
     * pagos_venta representa dinero realmente pagado; el crédito vivirá en una
     * cuenta por cobrar (B15).
     */
    public const METODOS = [
        self::METODO_EFECTIVO,
        self::METODO_TARJETA,
        self::METODO_TRANSFERENCIA,
    ];

    public const ORIGEN_POS = 'POS';

    public const ORIGEN_LEGACY = 'LEGACY';

    protected $table = 'pagos_venta';

    protected $fillable = [
        'venta_id',
        'sesion_caja_id',
        'user_id',
        'metodo',
        'monto_aplicado',
        'efectivo_recibido',
        'cambio_entregado',
        'referencia',
        'origen',
        'orden',
    ];

    protected $casts = [
        'venta_id' => 'integer',
        'sesion_caja_id' => 'integer',
        'user_id' => 'integer',
        'monto_aplicado' => 'decimal:2',
        'efectivo_recibido' => 'decimal:2',
        'cambio_entregado' => 'decimal:2',
        'orden' => 'integer',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function esEfectivo(): bool
    {
        return $this->metodo === self::METODO_EFECTIVO;
    }

    public function esLegacy(): bool
    {
        return $this->origen === self::ORIGEN_LEGACY;
    }
}
