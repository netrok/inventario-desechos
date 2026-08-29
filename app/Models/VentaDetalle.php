<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VentaDetalle extends Model
{
    protected $fillable = [
        'venta_id',
        'item_id',
        'precio',
    ];

    protected $casts = [
        'venta_id' => 'integer',
        'item_id' => 'integer',
        'precio' => 'decimal:2',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function item(): BelongsTo
    {
        // Una Venta histórica sigue siendo consultable aunque el Item quede
        // soft-deleted por un mecanismo técnico/legacy. Sin restaurar ni mutar.
        return $this->belongsTo(Item::class, 'item_id')->withTrashed();
    }

    public function documentoPostventaDetalle(): HasOne
    {
        return $this->hasOne(DocumentoPostventaDetalle::class, 'venta_detalle_id');
    }
}
