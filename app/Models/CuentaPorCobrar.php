<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class CuentaPorCobrar extends Model
{
    public const ESTADO_PENDIENTE = 'PENDIENTE';

    public const ESTADO_PARCIAL = 'PARCIAL';

    public const ESTADO_SALDADA = 'SALDADA';

    public const ESTADO_CANCELADA = 'CANCELADA';

    public const ESTADOS = [
        self::ESTADO_PENDIENTE,
        self::ESTADO_PARCIAL,
        self::ESTADO_SALDADA,
        self::ESTADO_CANCELADA,
    ];

    /**
     * Campos HISTÓRICOS de la deuda: inmutables tras el INSERT.
     * Solo saldo_centavos/estado/updated_at se modifican operacionalmente.
     * created_at es histórico (instantánea de nacimiento); updated_at no.
     */
    public const CAMPOS_HISTORICOS = [
        'folio',
        'venta_id',
        'cliente_id',
        'importe_original_centavos',
        'dias_credito_aplicados',
        'fecha_vencimiento',
        'created_at',
    ];

    /**
     * Nombre plural correcto (Eloquent no lo deduce de "CuentaPorCobrar").
     */
    protected $table = 'cuentas_por_cobrar';

    protected $fillable = [
        'folio',
        'venta_id',
        'cliente_id',
        'importe_original_centavos',
        'saldo_centavos',
        'dias_credito_aplicados',
        'fecha_vencimiento',
        'estado',
    ];

    protected $casts = [
        'venta_id' => 'integer',
        'cliente_id' => 'integer',
        'importe_original_centavos' => 'integer',
        'saldo_centavos' => 'integer',
        'dias_credito_aplicados' => 'integer',
        'fecha_vencimiento' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (CuentaPorCobrar $cuenta) {
            if (! empty($cuenta->folio)) {
                return;
            }

            $seq = DB::selectOne('SELECT nextval(\'cxc_folio_seq_generator\') AS seq');
            $next = (int) $seq->seq;

            $cuenta->folio = 'CXC-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        });

        static::updating(function (CuentaPorCobrar $cuenta) {
            $dirty = $cuenta->getDirty();

            foreach (self::CAMPOS_HISTORICOS as $campo) {
                if (array_key_exists($campo, $dirty)) {
                    throw new DomainException("El campo histórico {$campo} de la cuenta por cobrar es inmutable.");
                }
            }
        });

        static::deleting(function (CuentaPorCobrar $cuenta) {
            throw new DomainException('Las cuentas por cobrar no pueden eliminarse.');
        });
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCxC::class, 'cuenta_por_cobrar_id')->orderBy('id');
    }

    /**
     * VENCIDA es un estado DERIVADO, nunca persistido:
     * saldo > 0, fecha_vencimiento < hoy y estado != CANCELADA.
     */
    public function esVencida(): bool
    {
        if ($this->estado === self::ESTADO_CANCELADA || $this->saldo_centavos <= 0) {
            return false;
        }

        if ($this->fecha_vencimiento === null) {
            return false;
        }

        return $this->fecha_vencimiento->startOfDay()->lt(now()->copy()->startOfDay());
    }

    /**
     * Estado operativo derivado del saldo para las cuentas NO canceladas.
     *
     * Validación explícita de precondiciones por dominio.
     * NUNCA devuelve CANCELADA: ese estado solo pertenece a la operación
     * formal CANCELACION (nunca es función pura del saldo).
     */
    public static function estadoNormalDesdeSaldo(int $importeOriginalCentavos, int $saldoCentavos): string
    {
        if ($importeOriginalCentavos <= 0) {
            throw new DomainException('El importe original debe ser mayor que cero.');
        }

        if ($saldoCentavos < 0) {
            throw new DomainException('El saldo no puede ser negativo.');
        }

        if ($saldoCentavos > $importeOriginalCentavos) {
            throw new DomainException('El saldo no puede exceder el importe original.');
        }

        return match (true) {
            $saldoCentavos === $importeOriginalCentavos => self::ESTADO_PENDIENTE,
            $saldoCentavos > 0 => self::ESTADO_PARCIAL,
            default => self::ESTADO_SALDADA,
        };
    }
}
