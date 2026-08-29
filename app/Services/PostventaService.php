<?php

namespace App\Services;

use App\Models\DocumentoPostventa;
use App\Models\DocumentoPostventaDetalle;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Operaciones postventa atómicas: cancelación total y devolución.
 *
 * TODO dentro de DB::transaction() con locks deterministas:
 *   1) Venta (lockForUpdate)
 *   2) VentaDetalle(s) afectados (lockForUpdate, orden por id)
 *   3) Item(s) afectados (lockForUpdate, orden por id)
 *
 * Este orden es idéntico en cancelar() y devolver(), lo que evita deadlocks
 * y garantiza que dos operaciones sobre la misma venta/equipos queden
 * serializadas. Todo se revalida DESPUÉS de tomar los locks.
 */
class PostventaService
{
    /**
     * Cancelación TOTAL de una venta ACTIVA sin devoluciones previas.
     */
    public function cancelar(Venta $venta, string $motivo): DocumentoPostventa
    {
        return DB::transaction(function () use ($venta, $motivo): DocumentoPostventa {
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->getKey());

            if ($venta->estado !== Venta::ESTADO_ACTIVA) {
                throw new DomainException(
                    "La venta {$venta->folio} no está ACTIVA y no puede cancelarse."
                );
            }

            if ($venta->documentosPostventa()->exists()) {
                throw new DomainException(
                    "La venta {$venta->folio} ya tiene una operación postventa previa; no puede cancelarse."
                );
            }

            // Locks sobre los detalles (determinista por id).
            $detalles = $this->detallesBloqueados($venta);

            $itemIds = $detalles->pluck('item_id')->sort()->values()->all();
            $items = $this->itemsBloqueados($itemIds);

            // Revalidación bajo lock: TODOS los equipos deben seguir VENDIDO.
            foreach ($items as $item) {
                if ($item->estado !== 'VENDIDO') {
                    throw new DomainException(
                        "El equipo {$item->codigo} ya no está VENDIDO; la reversa total no puede continuar."
                    );
                }
            }

            // Importe siempre derivado server-side desde VentaDetalle (histórico).
            $totalCentavos = 0;
            foreach ($detalles as $detalle) {
                $totalCentavos += Money::aCentavos($detalle->precio);
            }

            if (Money::aCentavos($venta->total) !== $totalCentavos) {
                throw new DomainException(
                    "El importe revertido de la venta {$venta->folio} no coincide con sus detalles; operación abortada."
                );
            }

            $documento = DocumentoPostventa::create([
                'venta_id' => $venta->id,
                'tipo' => DocumentoPostventa::TIPO_CANCELACION,
                'user_id' => Auth::id(),
                'motivo' => trim($motivo),
                'forma_reembolso' => null,
                'total' => Money::aPrecio($totalCentavos),
            ]);

            $itemsPorId = $items->keyBy('id');

            foreach ($detalles as $detalle) {
                $item = $itemsPorId->get($detalle->item_id);

                DocumentoPostventaDetalle::create([
                    'documento_postventa_id' => $documento->id,
                    'venta_detalle_id' => $detalle->id,
                    'item_id' => $detalle->item_id,
                    'importe' => $detalle->precio,
                ]);

                $item->update(['estado' => 'DISPONIBLE']);

                Movimiento::create([
                    'item_id' => $item->id,
                    'user_id' => Auth::id(),
                    'tipo' => Movimiento::TIPO_CANCELACION_VENTA,
                    'de_estado' => 'VENDIDO',
                    'a_estado' => 'DISPONIBLE',
                    'de_ubicacion_id' => $item->ubicacion_id,
                    'a_ubicacion_id' => $item->ubicacion_id,
                    'notas' => "Cancelación de venta {$venta->folio} (documento {$documento->folio})",
                    'evidencia_path' => null,
                ]);
            }

            $venta->update(['estado' => Venta::ESTADO_CANCELADA]);

            return $documento->fresh();
        });
    }

    /**
     * Devolución (parcial o total) de Items de una venta.
     *
     * @param  array<int>  $ventaDetalleIds
     */
    public function devolver(Venta $venta, array $ventaDetalleIds, string $motivo, string $formaReembolso): DocumentoPostventa
    {
        $ids = array_values(array_unique(array_map('intval', $ventaDetalleIds)));

        if ($ids === []) {
            throw new DomainException('Selecciona al menos un equipo a devolver.');
        }

        return DB::transaction(function () use ($venta, $ids, $motivo, $formaReembolso): DocumentoPostventa {
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->getKey());

            if (! $venta->esElegibleParaDevolucion()) {
                throw new DomainException(
                    "La venta {$venta->folio} no admite devoluciones en su estado actual ({$venta->estado})."
                );
            }

            // Locks sobre los detalles seleccionados (determinista por id).
            $detalles = $this->detallesBloqueados($venta, $ids);

            if ($detalles->count() !== count($ids)) {
                throw new DomainException(
                    'Uno o más equipos seleccionados no pertenecen a la venta.'
                );
            }

            // No se puede devolver un detalle ya devuelto antes (defensa transaccional;
            // además hay UNIQUE(venta_detalle_id) a nivel BD como respaldo).
            $yaDevueltos = DocumentoPostventaDetalle::query()
                ->whereIn('venta_detalle_id', $detalles->pluck('id'))
                ->pluck('venta_detalle_id')
                ->all();

            if ($yaDevueltos !== []) {
                throw new DomainException(
                    'Uno o más equipos seleccionados ya fueron devueltos.'
                );
            }

            $itemIds = $detalles->pluck('item_id')->sort()->values()->all();
            $items = $this->itemsBloqueados($itemIds)->keyBy('id');

            // Importe siempre del precio histórico de VentaDetalle.
            $totalCentavos = 0;

            foreach ($detalles as $detalle) {
                $totalCentavos += Money::aCentavos($detalle->precio);
            }

            $documento = DocumentoPostventa::create([
                'venta_id' => $venta->id,
                'tipo' => DocumentoPostventa::TIPO_DEVOLUCION,
                'user_id' => Auth::id(),
                'motivo' => trim($motivo),
                'forma_reembolso' => $formaReembolso,
                'total' => Money::aPrecio($totalCentavos),
            ]);

            $totalDetalles = $venta->detalles()->count();

            foreach ($detalles as $detalle) {
                $item = $items->get($detalle->item_id);

                if ($item->estado !== 'VENDIDO') {
                    throw new DomainException(
                        "El equipo {$item->codigo} ya no está VENDIDO; la devolución no puede continuar."
                    );
                }

                DocumentoPostventaDetalle::create([
                    'documento_postventa_id' => $documento->id,
                    'venta_detalle_id' => $detalle->id,
                    'item_id' => $detalle->item_id,
                    'importe' => $detalle->precio,
                ]);

                $item->update(['estado' => 'DEVUELTO']);

                Movimiento::create([
                    'item_id' => $item->id,
                    'user_id' => Auth::id(),
                    'tipo' => Movimiento::TIPO_DEVOLUCION_VENTA,
                    'de_estado' => 'VENDIDO',
                    'a_estado' => 'DEVUELTO',
                    'de_ubicacion_id' => $item->ubicacion_id,
                    'a_ubicacion_id' => $item->ubicacion_id,
                    'notas' => "Devolución de venta {$venta->folio} (documento {$documento->folio})",
                    'evidencia_path' => null,
                ]);
            }

            // Derivar estado de Venta (nunca editable desde formularios generales).
            $devueltosEnVenta = DocumentoPostventaDetalle::query()
                ->join('documentos_postventa', 'documentos_postventa.id', '=', 'documento_postventa_detalles.documento_postventa_id')
                ->where('documentos_postventa.venta_id', $venta->id)
                ->where('documentos_postventa.tipo', DocumentoPostventa::TIPO_DEVOLUCION)
                ->distinct('venta_detalle_id')
                ->count('venta_detalle_id');

            $venta->update([
                'estado' => $devueltosEnVenta >= $totalDetalles
                    ? Venta::ESTADO_DEVUELTA
                    : Venta::ESTADO_PARCIALMENTE_DEVUELTA,
            ]);

            return $documento->fresh();
        });
    }

    /**
     * Detalles de la venta bajo lock (orden determinista por id).
     *
     * @param  array<int>|null  $soloIds
     */
    private function detallesBloqueados(Venta $venta, ?array $soloIds = null)
    {
        return VentaDetalle::query()
            ->when($soloIds !== null, fn ($q) => $q->whereIn('id', $soloIds))
            ->where('venta_id', $venta->id)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
    }

    /**
     * Items bajo lock (orden determinista por id).
     *
     * @param  array<int>  $itemIds
     */
    private function itemsBloqueados(array $itemIds)
    {
        return Item::query()
            ->whereIn('id', $itemIds)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
    }
}
