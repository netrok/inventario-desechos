<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Movimiento extends Model
{
    protected $fillable = [
        'item_id','tipo',
        'estado_anterior','estado_nuevo',
        'ubicacion_anterior_id','ubicacion_nueva_id',
        'user_id','detalle',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ubicacionAnterior(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_anterior_id');
    }

    public function ubicacionNueva(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_nueva_id');
    }
}
