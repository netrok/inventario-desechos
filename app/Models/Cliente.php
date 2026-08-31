<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Cliente extends Model
{
    use LogsActivity;

    public const TIPO_PERSONA = 'PERSONA';

    public const TIPO_EMPRESA = 'EMPRESA';

    public const TIPOS = [
        self::TIPO_PERSONA,
        self::TIPO_EMPRESA,
    ];

    protected $fillable = [
        // 'codigo' se genera SIEMPRE por la sequence PostgreSQL dentro del
        // evento creating. NO se incluye en $fillable para que el cliente nunca
        // pueda fijar/masivar un código arbitrario (manipulación).
        'tipo',
        'nombre',
        'rfc',
        'telefono',
        'email',
        'direccion',
        'notas',
        'activo',
        'credito_habilitado',
        'limite_credito',
        'dias_credito',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'credito_habilitado' => 'boolean',
        'limite_credito' => 'decimal:2',
        'dias_credito' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Cliente $cliente) {
            if (! empty($cliente->codigo)) {
                return;
            }

            $seq = DB::selectOne('SELECT nextval(\'clientes_codigo_seq_generator\') AS seq');
            $next = (int) $seq->seq;

            $cliente->codigo = 'CLI-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        });
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'cliente_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'tipo',
                'nombre',
                'rfc',
                'telefono',
                'email',
                'direccion',
                'notas',
                'activo',
                'credito_habilitado',
                'limite_credito',
                'dias_credito',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Cliente {$this->codigo} ".$eventName);
    }
}
