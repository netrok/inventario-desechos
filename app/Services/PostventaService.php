<?php

namespace App\Services;

use App\Models\DocumentoPostventa;
use App\Models\DocumentoPostventaDetalle;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\PagoVenta;
use App\Models\ReembolsoPostventa;
use App\Models\SesionCaja;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Support\Money;
use App\Support\ProrrateoReembolso;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Operaciones postventa atómicas.
 *
 * B14.2:
 * - Venta POS moderna:
 *      el reembolso se deriva SIEMPRE de PagoVenta.
 *      El operador no puede convertir tarjeta/transferencia en efectivo.
 *
 * - Venta legacy:
 *      conserva selección manual porque no existe evidencia suficiente
 *      para reconstruir el cobro original.
 *
 * Orden global de locks cuando puede salir EFECTIVO:
 *   1) Sesión de caja
 *   2) Venta
 *   3) PagoVenta
 *   4) VentaDetalle
 *   5) Item
 *
 * Esto conserva el orden de Caja/POS y reduce riesgo de deadlocks.
 */
class PostventaService
{
    private const PAGOS_AUTOMATICOS = 'AUTOMATICOS';

    private const PAGOS_LEGACY = 'LEGACY';

    /**
     * Cancelación TOTAL.
     *
     * @param  array<int|string, string|null>  $referenciasReembolso
     */
    public function cancelar(
        Venta $venta,
        string $motivo,
        ?string $formaReembolsoLegacy = null,
        array $referenciasReembolso = [],
        ?string $referenciaLegacy = null
    ): DocumentoPostventa {
        /*
         * Preflight sin lock únicamente para determinar si debemos bloquear
         * PRIMERO una sesión de caja. Todo se revalida dentro de la transacción.
         */
        $pagosPreflight = $this->pagosVenta($venta->id);
        $tipoPagosPreflight = $this->clasificarPagos($pagosPreflight);

        if ($tipoPagosPreflight === self::PAGOS_LEGACY) {
            $this->validarFormaLegacy($formaReembolsoLegacy);
        }

        $requiereCaja = $tipoPagosPreflight === self::PAGOS_AUTOMATICOS
            ? $pagosPreflight->contains(
                fn (PagoVenta $pago) => $pago->metodo === PagoVenta::METODO_EFECTIVO
            )
            : $formaReembolsoLegacy === DocumentoPostventa::FORMA_EFECTIVO;

        return DB::transaction(function () use (
            $venta,
            $motivo,
            $formaReembolsoLegacy,
            $referenciasReembolso,
            $referenciaLegacy,
            $tipoPagosPreflight,
            $requiereCaja
        ): DocumentoPostventa {
            $sesion = $this->sesionParaReembolsoEfectivo($requiereCaja);

            $venta = Venta::query()
                ->lockForUpdate()
                ->findOrFail($venta->getKey());

            $pagos = $this->pagosVenta($venta->id, true);
            $tipoPagos = $this->clasificarPagos($pagos);

            if ($tipoPagos !== $tipoPagosPreflight) {
                throw new DomainException(
                    'La composición de pagos cambió durante la operación. Intenta nuevamente.'
                );
            }

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

            if ($tipoPagos === self::PAGOS_AUTOMATICOS) {
                $this->validarPagosModernosContraVenta($venta, $pagos);
            }

            $detalles = $this->detallesBloqueados($venta);

            $itemIds = $detalles->pluck('item_id')->sort()->values()->all();
            $items = $this->itemsBloqueados($itemIds);

            foreach ($items as $item) {
                if ($item->estado !== 'VENDIDO') {
                    throw new DomainException(
                        "El equipo {$item->codigo} ya no está VENDIDO; la reversa total no puede continuar."
                    );
                }
            }

            $totalCentavos = 0;

            foreach ($detalles as $detalle) {
                $totalCentavos += Money::aCentavos($detalle->precio);
            }

            if (Money::aCentavos($venta->total) !== $totalCentavos) {
                throw new DomainException(
                    "El importe revertido de la venta {$venta->folio} no coincide con sus detalles; operación abortada."
                );
            }

            $formaResumen = $tipoPagos === self::PAGOS_AUTOMATICOS
                ? $this->formaResumenAutomatica($pagos)
                : $formaReembolsoLegacy;

            $documento = DocumentoPostventa::create([
                'venta_id' => $venta->id,
                'tipo' => DocumentoPostventa::TIPO_CANCELACION,
                'user_id' => Auth::id(),
                'motivo' => trim($motivo),
                'forma_reembolso' => $formaResumen,
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

            $venta->update([
                'estado' => Venta::ESTADO_CANCELADA,
            ]);

            $this->registrarReembolsoEconomico(
                venta: $venta,
                documento: $documento,
                pagos: $pagos,
                tipoPagos: $tipoPagos,
                totalCentavos: $totalCentavos,
                sesion: $sesion,
                formaReembolsoLegacy: $formaReembolsoLegacy,
                referenciasReembolso: $referenciasReembolso,
                referenciaLegacy: $referenciaLegacy
            );

            return $documento->fresh([
                'detalles',
                'reembolsos',
                'reembolsos.pagoVenta',
            ]);
        });
    }

    /**
     * Devolución parcial o total.
     *
     * @param  array<int>  $ventaDetalleIds
     * @param  array<int|string, string|null>  $referenciasReembolso
     */
    public function devolver(
        Venta $venta,
        array $ventaDetalleIds,
        string $motivo,
        ?string $formaReembolsoLegacy = null,
        array $referenciasReembolso = [],
        ?string $referenciaLegacy = null
    ): DocumentoPostventa {
        $ids = array_values(array_unique(array_map('intval', $ventaDetalleIds)));

        if ($ids === []) {
            throw new DomainException('Selecciona al menos un equipo a devolver.');
        }

        $pagosPreflight = $this->pagosVenta($venta->id);
        $tipoPagosPreflight = $this->clasificarPagos($pagosPreflight);

        if ($tipoPagosPreflight === self::PAGOS_LEGACY) {
            $this->validarFormaLegacy($formaReembolsoLegacy);
        }

        $requiereCaja = $tipoPagosPreflight === self::PAGOS_AUTOMATICOS
            ? $pagosPreflight->contains(
                fn (PagoVenta $pago) => $pago->metodo === PagoVenta::METODO_EFECTIVO
            )
            : $formaReembolsoLegacy === DocumentoPostventa::FORMA_EFECTIVO;

        return DB::transaction(function () use (
            $venta,
            $ids,
            $motivo,
            $formaReembolsoLegacy,
            $referenciasReembolso,
            $referenciaLegacy,
            $tipoPagosPreflight,
            $requiereCaja
        ): DocumentoPostventa {
            $sesion = $this->sesionParaReembolsoEfectivo($requiereCaja);

            $venta = Venta::query()
                ->lockForUpdate()
                ->findOrFail($venta->getKey());

            $pagos = $this->pagosVenta($venta->id, true);
            $tipoPagos = $this->clasificarPagos($pagos);

            if ($tipoPagos !== $tipoPagosPreflight) {
                throw new DomainException(
                    'La composición de pagos cambió durante la operación. Intenta nuevamente.'
                );
            }

            if (! $venta->esElegibleParaDevolucion()) {
                throw new DomainException(
                    "La venta {$venta->folio} no admite devoluciones en su estado actual ({$venta->estado})."
                );
            }

            if ($tipoPagos === self::PAGOS_AUTOMATICOS) {
                $this->validarPagosModernosContraVenta($venta, $pagos);
                $this->validarHistorialReembolsable($venta);
            }

            $detalles = $this->detallesBloqueados($venta, $ids);

            if ($detalles->count() !== count($ids)) {
                throw new DomainException(
                    'Uno o más equipos seleccionados no pertenecen a la venta.'
                );
            }

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

            $totalCentavos = 0;

            foreach ($detalles as $detalle) {
                $totalCentavos += Money::aCentavos($detalle->precio);
            }

            $formaResumen = $tipoPagos === self::PAGOS_AUTOMATICOS
                ? $this->formaResumenAutomatica($pagos)
                : $formaReembolsoLegacy;

            $documento = DocumentoPostventa::create([
                'venta_id' => $venta->id,
                'tipo' => DocumentoPostventa::TIPO_DEVOLUCION,
                'user_id' => Auth::id(),
                'motivo' => trim($motivo),
                'forma_reembolso' => $formaResumen,
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

            $devueltosEnVenta = DocumentoPostventaDetalle::query()
                ->join(
                    'documentos_postventa',
                    'documentos_postventa.id',
                    '=',
                    'documento_postventa_detalles.documento_postventa_id'
                )
                ->where('documentos_postventa.venta_id', $venta->id)
                ->where('documentos_postventa.tipo', DocumentoPostventa::TIPO_DEVOLUCION)
                ->distinct('venta_detalle_id')
                ->count('venta_detalle_id');

            $venta->update([
                'estado' => $devueltosEnVenta >= $totalDetalles
                    ? Venta::ESTADO_DEVUELTA
                    : Venta::ESTADO_PARCIALMENTE_DEVUELTA,
            ]);

            $this->registrarReembolsoEconomico(
                venta: $venta,
                documento: $documento,
                pagos: $pagos,
                tipoPagos: $tipoPagos,
                totalCentavos: $totalCentavos,
                sesion: $sesion,
                formaReembolsoLegacy: $formaReembolsoLegacy,
                referenciasReembolso: $referenciasReembolso,
                referenciaLegacy: $referenciaLegacy
            );

            return $documento->fresh([
                'detalles',
                'reembolsos',
                'reembolsos.pagoVenta',
            ]);
        });
    }

    /**
     * @param  Collection<int, PagoVenta>  $pagos
     * @param  array<int|string, string|null>  $referenciasReembolso
     */
    private function registrarReembolsoEconomico(
        Venta $venta,
        DocumentoPostventa $documento,
        Collection $pagos,
        string $tipoPagos,
        int $totalCentavos,
        ?SesionCaja $sesion,
        ?string $formaReembolsoLegacy,
        array $referenciasReembolso,
        ?string $referenciaLegacy
    ): void {
        if ($tipoPagos === self::PAGOS_LEGACY) {
            $this->registrarReembolsoLegacy(
                $venta,
                $documento,
                $totalCentavos,
                $sesion,
                $formaReembolsoLegacy,
                $referenciaLegacy
            );

            return;
        }

        $pagosParaProrrateo = [];

        foreach ($pagos as $pago) {
            $pagosParaProrrateo[] = [
                'id' => $pago->id,
                'monto' => Money::aCentavos($pago->monto_aplicado),
                'orden' => $pago->orden,
            ];
        }

        /*
         * Todo lo reembolsado ANTES del documento actual.
         * El documento actual todavía no tiene filas ReembolsoPostventa.
         */
        $yaReembolsado = [];

        $anteriores = ReembolsoPostventa::query()
            ->whereIn('pago_venta_id', $pagos->pluck('id'))
            ->lockForUpdate()
            ->get();

        foreach ($anteriores as $anterior) {
            $pagoId = (int) $anterior->pago_venta_id;

            $yaReembolsado[$pagoId] =
                ($yaReembolsado[$pagoId] ?? 0)
                + Money::aCentavos($anterior->monto);
        }

        $distribucion = ProrrateoReembolso::calcular(
            $pagosParaProrrateo,
            $yaReembolsado,
            $totalCentavos
        );

        $pagosPorId = $pagos->keyBy('id');
        $sumaRegistrada = 0;

        foreach ($distribucion as $pagoId => $montoCentavos) {
            /** @var PagoVenta|null $pago */
            $pago = $pagosPorId->get($pagoId);

            if (! $pago instanceof PagoVenta) {
                throw new DomainException(
                    'No fue posible relacionar el reembolso con su pago original.'
                );
            }

            $referencia = $this->referenciaParaPago(
                $pago,
                $referenciasReembolso
            );

            $reembolso = ReembolsoPostventa::create([
                'documento_postventa_id' => $documento->id,
                'pago_venta_id' => $pago->id,
                'sesion_caja_id' => $pago->esEfectivo()
                    ? $sesion?->id
                    : null,
                'user_id' => Auth::id(),
                'metodo' => $pago->metodo,
                'monto' => Money::aPrecio($montoCentavos),
                'referencia' => $referencia,
                'origen' => ReembolsoPostventa::ORIGEN_AUTOMATICO,
                'orden' => $pago->orden,
            ]);

            if ($pago->esEfectivo()) {
                if (! $sesion instanceof SesionCaja) {
                    throw new DomainException(
                        'Debes abrir una caja para realizar el componente de reembolso en efectivo.'
                    );
                }

                app(CajaService::class)->registrarReembolsoEfectivo(
                    $sesion,
                    Auth::user(),
                    $montoCentavos,
                    [
                        'venta_id' => $venta->id,
                        'pago_venta_id' => $pago->id,
                        'documento_postventa_id' => $documento->id,
                    ],
                    "Reembolso en efectivo {$venta->folio} ({$documento->folio})"
                );
            }

            $sumaRegistrada += Money::aCentavos($reembolso->monto);
        }

        if ($sumaRegistrada !== $totalCentavos) {
            throw new DomainException(
                'El desglose de reembolsos no coincide con el importe del documento postventa.'
            );
        }
    }

    private function registrarReembolsoLegacy(
        Venta $venta,
        DocumentoPostventa $documento,
        int $totalCentavos,
        ?SesionCaja $sesion,
        ?string $formaReembolso,
        ?string $referencia
    ): void {
        $this->validarFormaLegacy($formaReembolso);

        $referencia = $this->validarReferenciaElectronica(
            $formaReembolso,
            $referencia
        );

        ReembolsoPostventa::create([
            'documento_postventa_id' => $documento->id,
            'pago_venta_id' => null,
            'sesion_caja_id' => $formaReembolso === DocumentoPostventa::FORMA_EFECTIVO
                ? $sesion?->id
                : null,
            'user_id' => Auth::id(),
            'metodo' => $formaReembolso,
            'monto' => Money::aPrecio($totalCentavos),
            'referencia' => $referencia,
            'origen' => ReembolsoPostventa::ORIGEN_LEGACY_MANUAL,
            'orden' => 1,
        ]);

        if ($formaReembolso !== DocumentoPostventa::FORMA_EFECTIVO) {
            return;
        }

        if (! $sesion instanceof SesionCaja) {
            throw new DomainException(
                'Debes abrir una caja para realizar un reembolso en efectivo.'
            );
        }

        app(CajaService::class)->registrarReembolsoEfectivo(
            $sesion,
            Auth::user(),
            $totalCentavos,
            [
                'venta_id' => $venta->id,
                'documento_postventa_id' => $documento->id,
            ],
            "Reembolso legacy en efectivo {$venta->folio} ({$documento->folio})"
        );
    }

    /**
     * Una venta moderna con operaciones postventa anteriores debe tener el
     * desglose B14.2 de TODAS ellas. Si no, no inventamos cómo se reembolsó.
     */
    private function validarHistorialReembolsable(Venta $venta): void
    {
        $documentosPrevios = DocumentoPostventa::query()
            ->where('venta_id', $venta->id)
            ->withCount('reembolsos')
            ->get();

        if ($documentosPrevios->contains(fn ($doc) => $doc->reembolsos_count === 0)) {
            throw new DomainException(
                'La venta tiene una operación postventa histórica sin desglose de reembolso. Requiere conciliación administrativa antes de otra devolución.'
            );
        }
    }

    /**
     * @param  Collection<int, PagoVenta>  $pagos
     */
    private function validarPagosModernosContraVenta(
        Venta $venta,
        Collection $pagos
    ): void {
        $totalPagado = 0;

        foreach ($pagos as $pago) {
            if (! in_array($pago->metodo, PagoVenta::METODOS, true)) {
                throw new DomainException(
                    "El método {$pago->metodo} no admite reembolso automático en B14."
                );
            }

            $totalPagado += Money::aCentavos($pago->monto_aplicado);
        }

        if ($totalPagado !== Money::aCentavos($venta->total)) {
            throw new DomainException(
                'Los pagos originales no concilian con el total de la venta.'
            );
        }
    }

    /**
     * @param  Collection<int, PagoVenta>  $pagos
     */
    private function formaResumenAutomatica(Collection $pagos): ?string
    {
        $metodos = $pagos
            ->pluck('metodo')
            ->unique()
            ->values();

        if ($metodos->count() === 1) {
            return $metodos->first();
        }

        /*
         * documentos_postventa.forma_reembolso es un campo legacy de resumen
         * y no admite MIXTO. El detalle autoritativo vive en reembolsos_postventa.
         */
        return null;
    }

    /**
     * @param  Collection<int, PagoVenta>  $pagos
     */
    private function clasificarPagos(Collection $pagos): string
    {
        if ($pagos->isEmpty()) {
            return self::PAGOS_LEGACY;
        }

        $origenes = $pagos
            ->pluck('origen')
            ->unique()
            ->values();

        if ($origenes->count() !== 1) {
            throw new DomainException(
                'La venta mezcla pagos POS y LEGACY; requiere conciliación administrativa.'
            );
        }

        if ($origenes->first() === PagoVenta::ORIGEN_LEGACY) {
            return self::PAGOS_LEGACY;
        }

        if ($origenes->first() !== PagoVenta::ORIGEN_POS) {
            throw new DomainException(
                'La venta tiene un origen de pagos no reconocido.'
            );
        }

        return self::PAGOS_AUTOMATICOS;
    }

    /**
     * @return Collection<int, PagoVenta>
     */
    private function pagosVenta(int $ventaId, bool $bloquear = false): Collection
    {
        $query = PagoVenta::query()
            ->where('venta_id', $ventaId)
            ->orderBy('orden')
            ->orderBy('id');

        if ($bloquear) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param  array<int|string, string|null>  $referencias
     */
    private function referenciaParaPago(
        PagoVenta $pago,
        array $referencias
    ): ?string {
        if ($pago->metodo === PagoVenta::METODO_EFECTIVO) {
            return null;
        }

        $referencia = $referencias[$pago->id] ?? null;

        return $this->validarReferenciaElectronica(
            $pago->metodo,
            $referencia
        );
    }

    private function validarReferenciaElectronica(
        ?string $metodo,
        ?string $referencia
    ): ?string {
        $requiere = in_array(
            $metodo,
            [
                PagoVenta::METODO_TARJETA,
                PagoVenta::METODO_TRANSFERENCIA,
            ],
            true
        );

        $referencia = $referencia === null
            ? null
            : trim($referencia);

        if ($requiere && ($referencia === null || $referencia === '')) {
            throw new DomainException(
                "El reembolso {$metodo} requiere una referencia de devolución."
            );
        }

        if ($referencia !== null && mb_strlen($referencia) > 100) {
            throw new DomainException(
                'La referencia de devolución no puede exceder 100 caracteres.'
            );
        }

        return $referencia === '' ? null : $referencia;
    }

    private function validarFormaLegacy(?string $formaReembolso): void
    {
        if (
            $formaReembolso === null
            || ! in_array(
                $formaReembolso,
                DocumentoPostventa::FORMAS_REEMBOLSO,
                true
            )
        ) {
            throw new DomainException(
                'La forma de reembolso legacy no es válida.'
            );
        }
    }

    /**
     * Lock de caja PRIMERO cuando existe cualquier componente en efectivo.
     */
    private function sesionParaReembolsoEfectivo(
        bool $requiereEfectivo
    ): ?SesionCaja {
        if (! $requiereEfectivo) {
            return null;
        }

        $sesion = SesionCaja::query()
            ->lockForUpdate()
            ->where('user_id_apertura', Auth::id())
            ->abiertas()
            ->first();

        if (! $sesion instanceof SesionCaja) {
            throw new DomainException(
                'Debes abrir una caja para realizar un reembolso con componente en efectivo.'
            );
        }

        return $sesion;
    }

    /**
     * @param  array<int>|null  $soloIds
     */
    private function detallesBloqueados(
        Venta $venta,
        ?array $soloIds = null
    ): Collection {
        return VentaDetalle::query()
            ->when(
                $soloIds !== null,
                fn ($q) => $q->whereIn('id', $soloIds)
            )
            ->where('venta_id', $venta->id)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int>  $itemIds
     */
    private function itemsBloqueados(array $itemIds): Collection
    {
        return Item::query()
            ->whereIn('id', $itemIds)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
    }
}
