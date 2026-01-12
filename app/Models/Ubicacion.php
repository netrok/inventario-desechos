<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ubicacion extends Model
{
    protected $table = 'ubicaciones';

    protected $fillable = [
        'nombre', 'codigo', 'descripcion', 'activa',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
