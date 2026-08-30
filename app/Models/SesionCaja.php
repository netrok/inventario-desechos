<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class SesionCaja extends Model
{
    public const ESTADO_ABIERTA = 'ABIERTA';

    public const ESTADO_CERRADA = 'CERRADA';

    public const ESTADOS = [self::ESTADO_ABIERTA, self::ESTADO_CERRADA];

    protected $table = 'sesiones_caja';

    protected $fillable = [
        'folio',
        'caja_id',
        'user_id_apertura',
        'user_id_cierre',
        'opened_at',
        'fondo_inicial',
        'estado',
        'closed_at',
        'efectivo_contado',
        'efectivo_esperado',
        'diferencia',
        'observaciones_apertura',
        'observaciones_cierre',
    ];

    protected $casts = [
        'caja_id' => 'integer',
        'user_id_apertura' => 'integer',
        'user_id_cierre' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'fondo_inicial' => 'decimal:2',
        'efectivo_contado' => 'decimal:2',
        'efectivo_esperado' => 'decimal:2',
        'diferencia' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (SesionCaja $sesion) {
            if (! empty($sesion->folio)) {
                return;
            }

            $seq = DB::selectOne('SELECT nextval(\'sesiones_caja_folio_seq_generator\') AS seq');
            $next = (int) $seq->seq;

            $sesion->folio = 'COR-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        });
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function usuarioApertura(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id_apertura');
    }

    public function usuarioCierre(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id_cierre');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoVenta::class, 'sesion_caja_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCaja::class, 'sesion_caja_id')->orderBy('id');
    }

    public function arqueos(): HasMany
    {
        return $this->hasMany(ArqueoCaja::class, 'sesion_caja_id');
    }

    public function estaAbierta(): bool
    {
        return $this->estado === self::ESTADO_ABIERTA;
    }

    public function scopeAbiertas($query)
    {
        return $query->where('estado', self::ESTADO_ABIERTA);
    }

    public function scopeCerradas($query)
    {
        return $query->where('estado', self::ESTADO_CERRADA);
    }
}
