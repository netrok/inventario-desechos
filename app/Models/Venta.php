<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Venta extends Model
{
    public const FORMAS_PAGO = ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'OTRO'];

    protected $fillable = [
        'folio',
        'user_id',
        'total',
        'forma_pago',
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
}
