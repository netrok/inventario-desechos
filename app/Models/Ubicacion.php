<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ubicacion extends Model
{
    // ✅ IMPORTANTE: por convención Laravel intentaría "ubicacions"
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
        return $this->hasMany(Item::class, 'ubicacion_id');
    }
}
