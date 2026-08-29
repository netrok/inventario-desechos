<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Venta extends Model
{
    public const FORMAS_PAGO = ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'OTRO'];

    public const ESTADO_ACTIVA = 'ACTIVA';

    public const ESTADO_PARCIALMENTE_DEVUELTA = 'PARCIALMENTE_DEVUELTA';

    public const ESTADO_DEVUELTA = 'DEVUELTA';

    public const ESTADO_CANCELADA = 'CANCELADA';

    public const ESTADOS = [
        self::ESTADO_ACTIVA,
        self::ESTADO_PARCIALMENTE_DEVUELTA,
        self::ESTADO_DEVUELTA,
        self::ESTADO_CANCELADA,
    ];

    protected $fillable = [
        'folio',
        'user_id',
        'total',
        'forma_pago',
        'estado',
        'notas',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'total' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Venta $venta) {
            if (! empty($venta->folio)) {
                return;
            }

            $seq = DB::selectOne('SELECT nextval(\'ventas_folio_seq_generator\') AS seq');
            $next = (int) $seq->seq;

            $venta->folio = 'VTA-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(VentaDetalle::class);
    }

    public function documentosPostventa(): HasMany
    {
        return $this->hasMany(DocumentoPostventa::class, 'venta_id');
    }

    /**
     * Elegibilidad para devolución: si quedan detalles no devueltos.
     */
    public function esElegibleParaDevolucion(): bool
    {
        return in_array($this->estado, [self::ESTADO_ACTIVA, self::ESTADO_PARCIALMENTE_DEVUELTA], true);
    }

    /**
     * Elegibilidad para cancelación total: solo ACTIVA y sin operación postventa previa.
     */
    public function esElegibleParaCancelacion(): bool
    {
        if ($this->estado !== self::ESTADO_ACTIVA) {
            return false;
        }

        return ! $this->documentosPostventa()->exists();
    }
}
