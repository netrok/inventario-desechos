<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Caja extends Model
{
    protected $fillable = [
        'codigo',
        'nombre',
        'activa',
        'descripcion',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Caja $caja) {
            if (! empty($caja->codigo)) {
                return;
            }

            $seq = DB::selectOne('SELECT nextval(\'cajas_codigo_seq_generator\') AS seq');
            $next = (int) $seq->seq;

            $caja->codigo = 'CAJ-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        });
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(SesionCaja::class, 'caja_id');
    }

    public function sesionesAbiertas(): HasMany
    {
        return $this->sesiones()->where('estado', SesionCaja::ESTADO_ABIERTA);
    }

    public function scopeActivas($query)
    {
        return $query->where('activa', true)->orderBy('codigo');
    }
}
