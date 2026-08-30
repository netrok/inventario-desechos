<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArqueoCaja extends Model
{
    public const TIPO_FINAL = 'FINAL';

    public $timestamps = false;

    protected $table = 'arqueos_caja';

    protected $fillable = [
        'sesion_caja_id',
        'user_id',
        'tipo',
        'efectivo_contado',
        'created_at',
    ];

    protected $casts = [
        'sesion_caja_id' => 'integer',
        'user_id' => 'integer',
        'efectivo_contado' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function denominaciones(): HasMany
    {
        return $this->hasMany(ArqueoDenominacion::class, 'arqueo_id')->orderByDesc('denominacion');
    }
}
