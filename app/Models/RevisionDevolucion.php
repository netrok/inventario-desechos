<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Revisión formal de un artículo devuelto (trazabilidad B13).
 *
 * Cada devolución concreta (documento_postventa_detalles) se revisa una sola
 * vez; el resultado deriva el Item a DISPONIBLE, REPARACION o BAJA y genera un
 * Movimiento TIPO_REVISION_DEVOLUCION. Registro inmutable: no se edita ni se
 * elimina (evidencia administrativa).
 */
class RevisionDevolucion extends Model
{
    public const RESULTADO_DISPONIBLE = 'DISPONIBLE';

    public const RESULTADO_REPARACION = 'REPARACION';

    public const RESULTADO_BAJA = 'BAJA';

    public const RESULTADOS = [
        self::RESULTADO_DISPONIBLE,
        self::RESULTADO_REPARACION,
        self::RESULTADO_BAJA,
    ];

    protected $table = 'revisiones_devolucion';

    protected $fillable = [
        'item_id',
        'documento_postventa_detalle_id',
        'user_id',
        'resultado',
        'observaciones',
    ];

    protected $casts = [
        'item_id' => 'integer',
        'documento_postventa_detalle_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(DocumentoPostventaDetalle::class, 'documento_postventa_detalle_id');
    }
}
