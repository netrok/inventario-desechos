<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        // Legacy temporal (si aún lo usas en UI)
        'categoria',

        // FK catálogo
        'categoria_id',

        'estado',
        'ubicacion_id',
        'notas',
        'foto_path',
    ];

    protected $casts = [
        'codigo_seq' => 'integer',
        'categoria_id' => 'integer',
        'ubicacion_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Item $item) {
            if (!empty($item->codigo) && !empty($item->codigo_seq)) {
                return;
            }

            // ✅ En PostgreSQL evita duplicados con lock de tabla (simple y efectivo)
            // Nota: esto corre dentro del insert; si quieres ultra-pro, usa sequence nativa.
            $next = DB::transaction(function () {
                DB::statement('LOCK TABLE items IN EXCLUSIVE MODE');
                return (int) (DB::table('items')->max('codigo_seq') ?? 0) + 1;
            });

            $item->codigo_seq = $item->codigo_seq ?: $next;
            $item->codigo = $item->codigo ?: ('ITM-' . str_pad((string) $item->codigo_seq, 6, '0', STR_PAD_LEFT));
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

    // ✅ Relación catálogo (nombre distinto para no chocar con campo legacy)
    public function categoriaRef(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }

    // ✅ Accesor: $item->foto_url
    public function getFotoUrlAttribute(): string
    {
        if ($this->foto_path && Storage::disk('public')->exists($this->foto_path)) {
            return Storage::url($this->foto_path);
        }

        return asset('images/item-placeholder.png');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
