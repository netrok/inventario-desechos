<?php

namespace App\Services;

use App\Models\DocumentoPostventaDetalle;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\RevisionDevolucion;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Revisión formal de artículos devueltos (B13).
 *
 * Es la ÚNICA vía de salida del estado DEVUELTO. Opera atómica y
 * transaccionalmente: toma el detalle de devolución y el Item con
 * lockForUpdate en el MIMO orden que el flujo postventa (detalle -> item)
 * para evitar deadlocks, revalida las precondiciones bajo lock y persiste
 * revisión + cambio de estado + movimiento de trazabilidad en una sola
 * transacción. Registros sin soft-delete y sin edición posterior.
 */
class RevisionDevolucionService
{
    public function revisar(
        int $documentoPostventaDetalleId,
        string $resultado,
        ?string $observaciones = null,
        ?int $userId = null
    ): RevisionDevolucion {
        if (! in_array($resultado, RevisionDevolucion::RESULTADOS, true)) {
            throw new DomainException('El resultado de revisión no es válido.');
        }

        $usuarioId = $userId ?? Auth::id();

        return DB::transaction(function () use ($documentoPostventaDetalleId, $resultado, $observaciones, $usuarioId): RevisionDevolucion {
            // Orden de locks consistente con PostventaService: detalle -> item.
            $detalle = DocumentoPostventaDetalle::query()
                ->lockForUpdate()
                ->with('documento')
                ->findOrFail($documentoPostventaDetalleId);

            $item = Item::query()
                ->lockForUpdate()
                ->findOrFail($detalle->item_id);

            // (1) Cada devolución concreta solo puede revisarse una vez.
            $yaRevisada = RevisionDevolucion::query()
                ->where('documento_postventa_detalle_id', $detalle->id)
                ->exists();

            if ($yaRevisada) {
                throw new DomainException('Esta devolución ya fue revisada.');
            }

            // (2) La devolución concreta pertenece a este Item por construcción
            // (detalle.item_id). El Item debe seguir DEVUELTO bajo lock.
            if ($item->estado !== 'DEVUELTO') {
                throw new DomainException('El artículo no está DEVUELTO; no se puede revisar en este momento.');
            }

            $revision = RevisionDevolucion::create([
                'item_id' => $item->id,
                'documento_postventa_detalle_id' => $detalle->id,
                'user_id' => $usuarioId,
                'resultado' => $resultado,
                'observaciones' => $observaciones,
            ]);

            $item->update(['estado' => $resultado]);

            $notas = 'Revisión de devolución '
                .$detalle->documento->folio
                .": resultado {$resultado}"
                .(trim((string) $observaciones) !== '' ? ' — '.trim((string) $observaciones) : '');

            Movimiento::create([
                'item_id' => $item->id,
                'user_id' => $usuarioId,
                'tipo' => Movimiento::TIPO_REVISION_DEVOLUCION,
                'de_estado' => 'DEVUELTO',
                'a_estado' => $resultado,
                'de_ubicacion_id' => $item->ubicacion_id,
                'a_ubicacion_id' => $item->ubicacion_id,
                'notas' => $notas,
                'evidencia_path' => null,
            ]);

            return $revision;
        });
    }
}
