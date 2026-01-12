<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Movimiento extends Model
{
    protected $fillable = [
        'item_id',
        'user_id',
        'tipo',
        'de_estado',
        'a_estado',
        'de_ubicacion_id',
        'a_ubicacion_id',
        'notas',
        'evidencia_path',
        // 'fecha', // ❌ NO existe en tu tabla
    ];

    protected $casts = [
        'item_id' => 'integer',
        'user_id' => 'integer',
        'de_ubicacion_id' => 'integer',
        'a_ubicacion_id' => 'integer',
        // 'fecha' => 'datetime', // ❌ quítalo si no existe la columna
    ];

    // ✅ Siempre “los últimos” por defecto (Laravel-friendly)
    protected static function booted(): void
    {
        static::addGlobalScope('latest_first', function ($q) {
            $q->orderByDesc('created_at')->orderByDesc('id');
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deUbicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class, 'de_ubicacion_id');
    }

    public function aUbicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class, 'a_ubicacion_id');
    }

    // ✅ Usar como $mov->evidencia_url
    public function getEvidenciaUrlAttribute(): ?string
    {
        return $this->evidencia_path ? asset('storage/'.$this->evidencia_path) : null;
    }

    // ✅ Útil si quieres usarlo como método también
    public function evidenciaUrl(): ?string
    {
        return $this->evidencia_url;
    }
}
