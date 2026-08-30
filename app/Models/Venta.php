<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Venta extends Model
{
    public const FORMAS_PAGO = ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'MIXTO', 'OTRO'];

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
        'cliente_id',
        'cliente_codigo',
        'cliente_nombre',
        'cliente_rfc',
        'cliente_telefono',
        'cliente_email',
        'cliente_tipo',
        'total',
        'forma_pago',
        'estado',
        'notas',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'cliente_id' => 'integer',
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

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    /**
     * Presentación del cliente de la venta usando SIEMPRE el snapshot
     * histórico (nunca el Cliente actual, para no reescribir comprobantes).
     */
    public function getClienteHistoricoAttribute(): ?array
    {
        if ($this->cliente_nombre === null && $this->cliente_codigo === null) {
            return null;
        }

        return [
            'codigo' => $this->cliente_codigo,
            'nombre' => $this->cliente_nombre,
            'rfc' => $this->cliente_rfc,
            'telefono' => $this->cliente_telefono,
            'email' => $this->cliente_email,
            'tipo' => $this->cliente_tipo,
        ];
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(VentaDetalle::class);
    }

    public function documentosPostventa(): HasMany
    {
        return $this->hasMany(DocumentoPostventa::class, 'venta_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoVenta::class, 'venta_id')->orderBy('orden')->orderBy('id');
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
