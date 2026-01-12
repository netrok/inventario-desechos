<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ubicacion extends Model
{
    // Laravel ya infiere "ubicaciones" de "Ubicacion", pero no estorba dejarlo
    protected $table = 'ubicaciones';

    protected $fillable = [
        'nombre',
        'descripcion',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(\App\Models\Item::class, 'ubicacion_id');
    }
}
