<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    protected $fillable = ['nombre','activo'];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
