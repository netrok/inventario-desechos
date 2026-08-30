<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class DocumentoPostventa extends Model
{
    protected $table = 'documentos_postventa';

    public const TIPO_CANCELACION = 'CANCELACION';

    public const TIPO_DEVOLUCION = 'DEVOLUCION';

    public const TIPOS = [
        self::TIPO_CANCELACION,
        self::TIPO_DEVOLUCION,
    ];

    public const FORMA_EFECTIVO = 'EFECTIVO';

    public const FORMAS_REEMBOLSO = ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'OTRO'];

    protected $fillable = [
        'folio',
        'venta_id',
        'tipo',
        'user_id',
        'motivo',
        'forma_reembolso',
        'total',
    ];

    protected $casts = [
        'venta_id' => 'integer',
        'user_id' => 'integer',
        'total' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (DocumentoPostventa $documento) {
            if (! empty($documento->folio)) {
                return;
            }

            $seq = DB::selectOne('SELECT nextval(\'documentos_postventa_folio_seq_generator\') AS seq');
            $next = (int) $seq->seq;

            $documento->folio = 'DEV-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        });
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DocumentoPostventaDetalle::class, 'documento_postventa_id');
    }

    public function esCancelacion(): bool
    {
        return $this->tipo === self::TIPO_CANCELACION;
    }
}
