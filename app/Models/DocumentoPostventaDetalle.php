<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoPostventaDetalle extends Model
{
    protected $fillable = [
        'documento_postventa_id',
        'venta_detalle_id',
        'item_id',
        'importe',
    ];

    protected $casts = [
        'documento_postventa_id' => 'integer',
        'venta_detalle_id' => 'integer',
        'item_id' => 'integer',
        'importe' => 'decimal:2',
    ];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoPostventa::class, 'documento_postventa_id');
    }

    public function ventaDetalle(): BelongsTo
    {
        return $this->belongsTo(VentaDetalle::class, 'venta_detalle_id');
    }

    public function item(): BelongsTo
    {
        // Misma salvaguarda que VentaDetalle: consulta histórica resiliente.
        return $this->belongsTo(Item::class, 'item_id')->withTrashed();
    }
}
