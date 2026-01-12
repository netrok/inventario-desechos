<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

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

        // Legacy temporal (mientras migras y luego lo eliminas)
        'categoria',

        // ✅ Catálogo
        'categoria_id',

        'estado',
        'ubicacion_id',
        'notas',
        'foto_path',
    ];

    protected static function booted(): void
    {
        static::creating(function (Item $item) {
            if (!empty($item->codigo)) {
                return;
            }

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

    // ✅ Relación con catálogo de categorías (nombre distinto para no chocar con el campo legacy)
    public function categoriaRef(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function movimientos(): HasMany
    {
        // ✅ Si Movimiento tiene global scope latest_first (created_at desc, id desc),
        // aquí NO ordenes otra vez.
        return $this->hasMany(Movimiento::class);
    }

    // ✅ Accesor: úsalo como $item->foto_url
    public function getFotoUrlAttribute(): string
    {
        return $this->foto_path
            ? asset('storage/' . $this->foto_path)
            : asset('images/item-placeholder.png');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
