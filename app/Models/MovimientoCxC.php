<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MovimientoCxC extends Model
{
    public const TIPO_CARGO_INICIAL = 'CARGO_INICIAL';

    public const TIPO_ABONO = 'ABONO';

    public const TIPO_REVERSA_ABONO = 'REVERSA_ABONO';

    public const TIPO_REDUCCION_POSTVENTA = 'REDUCCION_POSTVENTA';

    public const TIPO_CANCELACION = 'CANCELACION';

    public const TIPOS = [
        self::TIPO_CARGO_INICIAL,
        self::TIPO_ABONO,
        self::TIPO_REVERSA_ABONO,
        self::TIPO_REDUCCION_POSTVENTA,
        self::TIPO_CANCELACION,
    ];

    public const METODO_EFECTIVO = 'EFECTIVO';

    public const METODO_TARJETA = 'TARJETA';

    public const METODO_TRANSFERENCIA = 'TRANSFERENCIA';

    public const METODOS = [
        self::METODO_EFECTIVO,
        self::METODO_TARJETA,
        self::METODO_TRANSFERENCIA,
    ];

    /**
     * Ledger append-only: sin updated_at, created_at lo genera la BD.
     * Las filas nunca se actualizan ni eliminan (hay triggers que lo blindan).
     */
    public $timestamps = false;

    protected $table = 'movimientos_cxc';

    protected $fillable = [
        'cuenta_por_cobrar_id',
        'user_id',
        'tipo',
        'monto_centavos',
        'saldo_antes_centavos',
        'saldo_despues_centavos',
        'metodo',
        'referencia',
        'movimiento_origen_id',
        'observaciones',
    ];

    protected $casts = [
        'cuenta_por_cobrar_id' => 'integer',
        'user_id' => 'integer',
        'monto_centavos' => 'integer',
        'saldo_antes_centavos' => 'integer',
        'saldo_despues_centavos' => 'integer',
        'movimiento_origen_id' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (MovimientoCxC $movimiento) {
            throw new DomainException('Los movimientos CxC son de solo lectura (append-only).');
        });

        static::deleting(function (MovimientoCxC $movimiento) {
            throw new DomainException('Los movimientos CxC son de solo lectura (append-only).');
        });
    }

    public function cuentaPorCobrar(): BelongsTo
    {
        return $this->belongsTo(CuentaPorCobrar::class, 'cuenta_por_cobrar_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function movimientoOrigen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'movimiento_origen_id');
    }

    public function reversa(): HasOne
    {
        return $this->hasOne(self::class, 'movimiento_origen_id');
    }

    /**
     * Efecto del tipo sobre el saldo (+1 sube el saldo, -1 lo baja).
     * Solo interpretación; NO se persiste.
     */
    public static function efectoDeTipo(string $tipo): int
    {
        return match ($tipo) {
            self::TIPO_CARGO_INICIAL, self::TIPO_REVERSA_ABONO => +1,
            self::TIPO_ABONO, self::TIPO_REDUCCION_POSTVENTA, self::TIPO_CANCELACION => -1,
            default => throw new DomainException("Tipo de movimiento CxC desconocido: {$tipo}."),
        };
    }
}
