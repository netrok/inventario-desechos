<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReembolsoPostventa extends Model
{
    protected static function booted(): void
    {
        static::updating(function (ReembolsoPostventa $reembolso) {
            throw new DomainException('Los reembolsos postventa son históricos e inmutables.');
        });

        static::deleting(function (ReembolsoPostventa $reembolso) {
            throw new DomainException('Los reembolsos postventa son históricos e inmutables.');
        });
    }

    public const METODO_EFECTIVO = 'EFECTIVO';

    public const METODO_TARJETA = 'TARJETA';

    public const METODO_TRANSFERENCIA = 'TRANSFERENCIA';

    public const METODO_OTRO = 'OTRO';

    public const METODOS = [
        self::METODO_EFECTIVO,
        self::METODO_TARJETA,
        self::METODO_TRANSFERENCIA,
        self::METODO_OTRO,
    ];

    public const ORIGEN_AUTOMATICO = 'AUTOMATICO';

    public const ORIGEN_CXC_ABONO = 'CXC_ABONO';

    public const ORIGEN_LEGACY_MANUAL = 'LEGACY_MANUAL';

    protected $table = 'reembolsos_postventa';

    protected $fillable = [
        'documento_postventa_id',
        'pago_venta_id',
        'movimiento_cxc_id',
        'sesion_caja_id',
        'user_id',
        'metodo',
        'monto',
        'referencia',
        'origen',
        'orden',
    ];

    protected $casts = [
        'documento_postventa_id' => 'integer',
        'pago_venta_id' => 'integer',
        'movimiento_cxc_id' => 'integer',
        'sesion_caja_id' => 'integer',
        'user_id' => 'integer',
        'monto' => 'decimal:2',
        'orden' => 'integer',
    ];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoPostventa::class, 'documento_postventa_id');
    }

    public function pagoVenta(): BelongsTo
    {
        return $this->belongsTo(PagoVenta::class, 'pago_venta_id');
    }

    public function movimientoCxC(): BelongsTo
    {
        return $this->belongsTo(MovimientoCxC::class, 'movimiento_cxc_id');
    }

    public function sesionCaja(): BelongsTo
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

    public function esAutomatico(): bool
    {
        return $this->origen === self::ORIGEN_AUTOMATICO;
    }

    public function esCxC(): bool
    {
        return $this->origen === self::ORIGEN_CXC_ABONO;
    }
}
