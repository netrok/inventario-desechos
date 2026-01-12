<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Item extends Model
{
    use LogsActivity, SoftDeletes;

    public const ESTADOS = [
        'DISPONIBLE',
        'RESERVADO',
        'REPARACION',
        'VENDIDO',
        'BAJA',
    ];

    protected $fillable = [
        'codigo',
        'codigo_seq',
        'serie',
        'marca',
        'modelo',
        'categoria',
        'estado',
        'ubicacion_id',
        'notas',
        'foto_path', // ✅ NUEVO
    ];

    protected static function booted(): void
    {
        static::creating(function (Item $item) {
            // Si ya viene código manual, respeta
            if (!empty($item->codigo)) {
                return;
            }

            // Requiere columna codigo_seq en DB
            $max = (int) (self::max('codigo_seq') ?? 0);

            $item->codigo_seq = $max + 1;
            $item->codigo = 'ITM-' . str_pad((string) $item->codigo_seq, 6, '0', STR_PAD_LEFT);
        });
    }

    public static function canTransition(string $from, string $to): bool
    {
        $map = [
            'DISPONIBLE' => ['RESERVADO', 'REPARACION', 'BAJA', 'VENDIDO'],
            'RESERVADO'  => ['DISPONIBLE', 'VENDIDO', 'BAJA'],
            'REPARACION' => ['DISPONIBLE', 'BAJA'],
            'VENDIDO'    => [],
            'BAJA'       => [],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }

    public function fotoUrl(): ?string
    {
        return $this->foto_path ? asset('storage/' . $this->foto_path) : null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
