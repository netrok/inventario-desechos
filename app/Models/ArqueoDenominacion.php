<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArqueoDenominacion extends Model
{
    public $timestamps = false;

    protected $table = 'arqueo_denominaciones';

    protected $fillable = [
        'arqueo_id',
        'denominacion',
        'cantidad',
        'subtotal',
    ];

    protected $casts = [
        'arqueo_id' => 'integer',
        'denominacion' => 'decimal:2',
        'cantidad' => 'integer',
        'subtotal' => 'decimal:2',
    ];

    public function arqueo(): BelongsTo
    {
        return $this->belongsTo(ArqueoCaja::class, 'arqueo_id');
    }
}
