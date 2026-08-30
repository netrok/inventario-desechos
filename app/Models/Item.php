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
        'DEVUELTO',
        'BAJA',
    ];

    protected $fillable = [
        'codigo',
        'codigo_seq',
        'serie',
        'marca',
        'modelo',
        'categoria_id',
        'estado',
        'ubicacion_id',
        'notas',
        'precio',
        'foto_path',
    ];

    protected $casts = [
        'codigo_seq' => 'integer',
        'categoria_id' => 'integer',
        'ubicacion_id' => 'integer',
        'precio' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Item $item) {
            if (! empty($item->codigo) && ! empty($item->codigo_seq)) {
                return;
            }

            $seq = DB::selectOne('SELECT nextval(\'items_codigo_seq_generator\') AS seq');
            $next = (int) $seq->seq;

            $item->codigo_seq = $item->codigo_seq ?: $next;
            $item->codigo = $item->codigo ?: ('ITM-'.str_pad((string) $item->codigo_seq, 6, '0', STR_PAD_LEFT));
        });
    }

    /**
     * Transiciones manuales (cambio de estado por endpoint / formulario).
     *
     * VENDIDO NO aparece aquí como origen: no existe transición manual desde
     * VENDIDO. Las únicas salidas operativas de VENDIDO son el flujo postventa
     * controlado (PostventaService):
     *   - CANCELACIÓN atómica: VENDIDO -> DISPONIBLE
     *   - DEVOLUCIÓN atómica:  VENDIDO -> DEVUELTO
     *
     * DEVUELTO es un estado transitorio que NO admite salida manual (B13):
     * la única vía de salida es la revisión formal (RevisionDevolucionService),
     * que deriva el artículo a DISPONIBLE | REPARACION | BAJA como RESULTADO de
     * la revisión físico-administrativa de la devolución concreta.
     */
    public static function canTransition(string $from, string $to): bool
    {
        $map = [
            'DISPONIBLE' => ['RESERVADO', 'REPARACION', 'BAJA'],
            'RESERVADO' => ['DISPONIBLE', 'BAJA'],
            'REPARACION' => ['DISPONIBLE', 'BAJA'],
            'VENDIDO' => [],
            'DEVUELTO' => [],
            'BAJA' => [],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class, 'item_id');
    }

    public function revisiones(): HasMany
    {
        return $this->hasMany(RevisionDevolucion::class, 'item_id');
    }

    public function documentosPostventaDetalle(): HasMany
    {
        return $this->hasMany(DocumentoPostventaDetalle::class, 'item_id');
    }

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
