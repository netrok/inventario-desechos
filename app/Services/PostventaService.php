<?php

namespace App\Services;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\DocumentoPostventa;
use App\Models\DocumentoPostventaDetalle;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\MovimientoCxC;
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
     * @param  array<int|string, string|null>  $referenciasReembolsoCxc
     */
    public function cancelar(
        Venta $venta,
        string $motivo,
        ?string $formaReembolsoLegacy = null,
        array $referenciasReembolso = [],
        ?string $referenciaLegacy = null,
        array $referenciasReembolsoCxc = []
    ): DocumentoPostventa {
        if ($this->ventaTieneCuentaPorCobrar($venta->id)) {
            return $this->cancelarConCredito(
                $venta,
                $motivo,
                $referenciasReembolso,
                $referenciasReembolsoCxc
            );
        }

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
     * @param  array<int|string, string|null>  $referenciasReembolsoCxc
     */
    public function devolver(
        Venta $venta,
        array $ventaDetalleIds,
        string $motivo,
        ?string $formaReembolsoLegacy = null,
        array $referenciasReembolso = [],
        ?string $referenciaLegacy = null,
        array $referenciasReembolsoCxc = []
    ): DocumentoPostventa {
        $ids = array_values(array_unique(array_map('intval', $ventaDetalleIds)));

        if ($ids === []) {
            throw new DomainException('Selecciona al menos un equipo a devolver.');
        }

        if ($this->ventaTieneCuentaPorCobrar($venta->id)) {
            return $this->devolverConCredito(
                $venta,
                $ids,
                $motivo,
                $referenciasReembolso,
                $referenciasReembolsoCxc
            );
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
     * B15.5 — POSTVENTA DEBT-FIRST PARA VENTAS CON CxC.
     *
     * La postventa de una venta con CuentaPorCobrar resuelve el financiamiento
     * así: PRIMERO reduce la deuda del saldo por cobrar y solo el SOBRANTE se
     * devuelve como reembolso monetario. Nunca se entrega más dinero real del
     * que la venta ya tenía retenido en pagos+abonos.
     *
     * Orden global de locks (nunca invertir):
     *   1) SesionCaja        — solo si el plan preflight tiene EFECTIVO.
     *   2) Cliente           — mutex de exposición; mismo grafo que B15.4.
     *   3) Venta
     *   4) CuentaPorCobrar
     *   5) PagoVenta + MovimientoCxC tipo ABONO (ID ascendentes, FOR UPDATE)
     *   6) DocumentosPostventa / ReembolsosPostventa previos (solo devoluciones)
     *   7) VentaDetalle
     *   8) Item
     */
    private function ventaTieneCuentaPorCobrar(int $ventaId): bool
    {
        return $this->cuentaPorCobrarDeVenta($ventaId) !== null;
    }

    private function cuentaPorCobrarDeVenta(int $ventaId): ?CuentaPorCobrar
    {
        return CuentaPorCobrar::query()->where('venta_id', $ventaId)->first();
    }

    /**
     * Lectura económica PREFLIGHT (sin locks, fuera de transacción) usada para
     * planear y calcular la huella inicial. Dentro de la transacción se repite
     * con locks y se compara la huella; si algo cambió -> se aborta.
     */
    private function lecturaEconomicaCxc(Venta $venta): array
    {
        $cuenta = $this->cuentaPorCobrarDeVenta($venta->id);

        if (! $cuenta instanceof CuentaPorCobrar) {
            throw new DomainException('La venta perdió su cuenta por cobrar; reintente.');
        }

        $pagos = $this->pagosVenta($venta->id);

        $abonos = MovimientoCxC::query()
            ->where('cuenta_por_cobrar_id', $cuenta->id)
            ->where('tipo', MovimientoCxC::TIPO_ABONO)
            ->orderBy('id')
            ->get();

        $reversados = $this->reversadosDeAbonos($abonos);

        return [
            'cuenta' => $cuenta,
            'pagos' => $pagos,
            'abonos' => $abonos,
            'reversados' => $reversados,
            'yaPago' => $this->yaReembolsadoPorPago($pagos),
            'yaAbono' => $this->yaReembolsadoPorAbono($abonos),
        ];
    }

    /**
     * @param  Collection<int, MovimientoCxC>  $abonos
     * @return array<int, int> movimiento_id => centavos ya reembolsados
     */
    private function yaReembolsadoPorAbono(Collection $abonos): array
    {
        $ya = [];

        if ($abonos->isEmpty()) {
            return $ya;
        }

        ReembolsoPostventa::query()
            ->whereIn('movimiento_cxc_id', $abonos->pluck('id'))
            ->get()
            ->each(function (ReembolsoPostventa $reembolso) use (&$ya) {
                $movimientoCxcId = (int) $reembolso->movimiento_cxc_id;

                $ya[$movimientoCxcId] = ($ya[$movimientoCxcId] ?? 0)
                    + Money::aCentavos($reembolso->monto);
            });

        return $ya;
    }

    /**
     * @param  Collection<int, MovimientoCxC>  $abonos
     * @return array<int, int>
     */
    private function reversadosDeAbonos(Collection $abonos): array
    {
        if ($abonos->isEmpty()) {
            return [];
        }

        return MovimientoCxC::query()
            ->where('tipo', MovimientoCxC::TIPO_REVERSA_ABONO)
            ->whereIn('movimiento_origen_id', $abonos->pluck('id'))
            ->pluck('movimiento_origen_id')
            ->all();
    }

    /**
     * ABONOS reembolsables, LIFO por inserción (id desc). Un abono compensado
     * por REVERSA_ABONO nunca es fuente de reembolso monetario.
     *
     * @param  Collection<int, MovimientoCxC>  $abonos
     */
    private function abonosDisponibles(Collection $abonos, array $reversados): Collection
    {
        /*
         * LIFO exacto: ABONOS del MÁS RECIENTE al MÁS ANTIGUO.
         *
         * Política congelada B15.5: ordena por created_at DESC y, en caso de
         * empate temporal, por id DESC. El lock se adquiere en id ASC
         * (abonosBloqueados) para evitar deadlocks; la distribución se ordena
         * EN MEMORIA aquí, nunca por el orden de bloqueo.
         */
        return $abonos
            ->reject(fn (MovimientoCxC $abono) => in_array((int) $abono->id, $reversados, true))
            ->sort(function (MovimientoCxC $a, MovimientoCxC $b): int {
                if ($a->created_at->getTimestamp() !== $b->created_at->getTimestamp()) {
                    return $b->created_at->getTimestamp() <=> $a->created_at->getTimestamp();
                }

                return (int) $b->id <=> (int) $a->id;
            })
            ->values();
    }

    private function abonosBloqueados(CuentaPorCobrar $cuenta): Collection
    {
        return MovimientoCxC::query()
            ->where('cuenta_por_cobrar_id', $cuenta->id)
            ->where('tipo', MovimientoCxC::TIPO_ABONO)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, PagoVenta>  $pagos
     * @return array<int, int> pago_id => centavos ya reembolsados
     */
    private function yaReembolsadoPorPago(Collection $pagos): array
    {
        $ya = [];

        if ($pagos->isEmpty()) {
            return $ya;
        }

        ReembolsoPostventa::query()
            ->whereIn('pago_venta_id', $pagos->pluck('id'))
            ->get()
            ->each(function (ReembolsoPostventa $reembolso) use (&$ya) {
                $pagoId = (int) $reembolso->pago_venta_id;

                $ya[$pagoId] = ($ya[$pagoId] ?? 0)
                    + Money::aCentavos($reembolso->monto);
            });

        return $ya;
    }

    /**
     * Invariante económico B15.5:
     *   SUM(PagoVenta original) + CxC.importe_original_centavos === Venta.total
     * y todos los pagos representan dinero real (métodos operacionales).
     *
     * @param  Collection<int, PagoVenta>  $pagos
     */
    private function validarEstructuraEconomicaConCxc(
        Venta $venta,
        CuentaPorCobrar $cuenta,
        Collection $pagos
    ): void {
        $totalPagadoCentavos = 0;

        foreach ($pagos as $pago) {
            if (! in_array($pago->metodo, PagoVenta::METODOS, true)) {
                throw new DomainException(
                    "El método {$pago->metodo} no representa dinero real en el flujo B15.5."
                );
            }

            $totalPagadoCentavos += Money::aCentavos($pago->monto_aplicado);
        }

        if ($totalPagadoCentavos + (int) $cuenta->importe_original_centavos !== Money::aCentavos($venta->total)) {
            throw new DomainException(
                'La estructura de pagos y crédito no concilia con el total de la venta; requiere conciliación administrativa.'
            );
        }
    }

    /**
     * Desglose economía-deuda-primero de un documento postventa con crédito.
     *
     * @param  Collection<int, PagoVenta>  $pagos
     * @param  Collection<int, MovimientoCxC>  $abonosDisponibles  (LIFO)
     * @param  array<int, int>  $yaReembolsadoPorPago
     * @param  array<int, int>  $yaReembolsadoPorAbono
     * @return array{
     *   reduccion_deuda_centavos: int,
     *   reembolso_monetario_centavos: int,
     *   abonos: array<int, int>,
     *   pagos: array<int, int>,
     *   efectivo_centavos: int,
     *   forma: string|null
     * }
     */
    private function planearReembolsoConCxc(
        int $importeDocumentoCentavos,
        CuentaPorCobrar $cuenta,
        Collection $pagos,
        Collection $abonosDisponibles,
        array $yaReembolsadoPorPago,
        array $yaReembolsadoPorAbono
    ): array {
        $saldoCentavos = (int) $cuenta->saldo_centavos;

        $reduccionDeuda = min($saldoCentavos, $importeDocumentoCentavos);
        $reembolsoMonetario = $importeDocumentoCentavos - $reduccionDeuda;

        if ($reduccionDeuda > $saldoCentavos) {
            throw new DomainException(
                'El desglose planeado de deuda supera el saldo por cobrar; operación abortada.'
            );
        }

        $abonosPorId = $abonosDisponibles->keyBy('id');
        $pagosPorId = $pagos->keyBy('id');

        $asignacionAbonos = [];
        $restante = $reembolsoMonetario;

        foreach ($abonosDisponibles as $abono) {
            if ($restante <= 0) {
                break;
            }

            $disponible = (int) $abono->monto_centavos
                - (int) ($yaReembolsadoPorAbono[(int) $abono->id] ?? 0);

            if ($disponible <= 0) {
                continue;
            }

            $tomar = min($disponible, $restante);

            $asignacionAbonos[(int) $abono->id] = $tomar;
            $restante -= $tomar;
        }

        $asignacionPagos = [];

        if ($restante > 0) {
            if ($pagos->isEmpty()) {
                throw new DomainException(
                    'El monto a reembolsar supera el dinero retenido disponible; requiere conciliación administrativa.'
                );
            }

            $pagosParaProrrateo = $pagos
                ->map(fn (PagoVenta $pago) => [
                    'id' => (int) $pago->id,
                    'monto' => Money::aCentavos($pago->monto_aplicado),
                    'orden' => (int) $pago->orden,
                ])
                ->values()
                ->all();

            $asignacionPagos = ProrrateoReembolso::calcular(
                $pagosParaProrrateo,
                $yaReembolsadoPorPago,
                $restante
            );
        }

        $efectivoCentavos = 0;
        $metodos = [];

        foreach ($asignacionAbonos as $movimientoCxcId => $montoCentavos) {
            $metodo = $abonosPorId->get($movimientoCxcId)?->metodo;

            $efectivoCentavos += $metodo === MovimientoCxC::METODO_EFECTIVO ? $montoCentavos : 0;
            $metodos[] = $metodo;
        }

        foreach ($asignacionPagos as $pagoId => $montoCentavos) {
            $metodo = $pagosPorId->get($pagoId)?->metodo;

            $efectivoCentavos += $metodo === PagoVenta::METODO_EFECTIVO ? $montoCentavos : 0;
            $metodos[] = $metodo;
        }

        $metodosUnicos = array_values(array_unique(array_filter($metodos)));
        $forma = count($metodosUnicos) === 1 ? $metodosUnicos[0] : null;

        return [
            'reduccion_deuda_centavos' => $reduccionDeuda,
            'reembolso_monetario_centavos' => $reembolsoMonetario,
            'abonos' => $asignacionAbonos,
            'pagos' => $asignacionPagos,
            'efectivo_centavos' => $efectivoCentavos,
            'forma' => $forma,
        ];
    }

    /**
     * Huella económica determinista de la venta+cuenta. Si cambia entre el
     * preflight (sin locks) y el interior de la transacción (bajo locks),
     * el plan ya no es válido y se aborta la operación.
     *
     * @param  Collection<int, PagoVenta>  $pagos
     * @param  Collection<int, MovimientoCxC>  $abonos
     */
    private function huellaEconomicaCxc(
        int $importeDocumentoCentavos,
        Venta $venta,
        CuentaPorCobrar $cuenta,
        Collection $pagos,
        Collection $abonos,
        array $reversados,
        array $yaPago,
        array $yaAbono
    ): string {
        ksort($yaPago);
        ksort($yaAbono);

        $estado = [
            'importe' => $importeDocumentoCentavos,
            'venta' => [(int) $venta->id, Money::aCentavos($venta->total)],
            'cuenta' => [
                (int) $cuenta->id,
                (int) $cuenta->saldo_centavos,
                $cuenta->estado,
                (int) $cuenta->importe_original_centavos,
            ],
            'pagos' => $pagos
                ->map(fn (PagoVenta $pago) => [
                    (int) $pago->id,
                    Money::aCentavos($pago->monto_aplicado),
                    $pago->metodo,
                    (int) $pago->orden,
                ])
                ->values()
                ->all(),
            'abonos' => $abonos
                ->map(fn (MovimientoCxC $abono) => [
                    (int) $abono->id,
                    (int) $abono->monto_centavos,
                    $abono->metodo,
                ])
                ->values()
                ->all(),
            'reversados' => array_values(array_map('intval', $reversados)),
            'ya_pago' => $yaPago,
            'ya_abono' => $yaAbono,
        ];

        return md5(serialize($estado));
    }

    /**
     * Locks 2..6 del orden global + primitivas económicas refrescadas bajo los
     * locks. En devoluciones (reconciliarHistorial=true) además se valida que
     * cada documento previo cumpla: total === deuda + SUM(reembolsos).
     *
     * @return array{
     *   venta: Venta,
     *   cuenta: CuentaPorCobrar,
     *   pagos: Collection,
     *   abonos: Collection,
     *   reversados: array<int, int>,
     *   yaPago: array<int, int>,
     *   yaAbono: array<int, int>
     * }
     */
    private function bloquearEconomiaConCxc(
        Venta $venta,
        int $cuentaId,
        bool $reconciliarHistorial
    ): array {
        $cuentaPreflight = $this->cuentaPorCobrarDeVenta($venta->id);

        if (! $cuentaPreflight instanceof CuentaPorCobrar) {
            throw new DomainException(
                'La situación económica de la venta cambió durante la operación. Recarga e inténtalo nuevamente.'
            );
        }

        // Lock 2: Cliente (mutex de exposición; mismo grafo que B15.4).
        Cliente::query()->lockForUpdate()->findOrFail((int) $cuentaPreflight->cliente_id);

        // Lock 3: Venta.
        $venta = Venta::query()->lockForUpdate()->findOrFail($venta->getKey());

        // Lock 4: CuentaPorCobrar.
        $cuenta = CuentaPorCobrar::query()->lockForUpdate()->findOrFail($cuentaId);

        if ((int) $cuenta->venta_id !== (int) $venta->id) {
            throw new DomainException(
                'La situación económica de la venta cambió durante la operación. Recarga e inténtalo nuevamente.'
            );
        }

        // Lock 5: PagoVenta + ABONOS (ID ascendentes).
        $pagos = $this->pagosVenta($venta->id, true);
        $abonos = $this->abonosBloqueados($cuenta);
        $reversados = $this->reversadosDeAbonos($abonos);

        // Lock 6: historial postventa previo (solo devoluciones).
        if ($reconciliarHistorial) {
            $this->reconciliarHistorialConCredito($venta);
        }

        $yaPago = $this->yaReembolsadoPorPago($pagos);
        $yaAbono = $this->yaReembolsadoPorAbono($abonos);

        $this->validarEstructuraEconomicaConCxc($venta, $cuenta, $pagos);

        return [
            'venta' => $venta,
            'cuenta' => $cuenta,
            'pagos' => $pagos,
            'abonos' => $abonos,
            'reversados' => $reversados,
            'yaPago' => $yaPago,
            'yaAbono' => $yaAbono,
        ];
    }

    private function reconciliarHistorialConCredito(Venta $venta): void
    {
        $previos = DocumentoPostventa::query()
            ->where('venta_id', $venta->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($previos->isEmpty()) {
            return;
        }

        $previosIds = $previos->pluck('id')->all();

        $reembolsos = ReembolsoPostventa::query()
            ->whereIn('documento_postventa_id', $previosIds)
            ->lockForUpdate()
            ->get();

        $deudasPorDocumento = MovimientoCxC::query()
            ->whereIn('documento_postventa_id', $previosIds)
            ->whereIn('tipo', [
                MovimientoCxC::TIPO_REDUCCION_POSTVENTA,
                MovimientoCxC::TIPO_CANCELACION,
            ])
            ->selectRaw('documento_postventa_id, SUM(monto_centavos) AS total')
            ->groupBy('documento_postventa_id')
            ->pluck('total', 'documento_postventa_id');

        foreach ($previos as $previo) {
            $previoId = (int) $previo->id;

            $reembolsadoCentavos = $reembolsos
                ->where('documento_postventa_id', $previoId)
                ->sum(fn (ReembolsoPostventa $reembolso) => Money::aCentavos($reembolso->monto));

            $deudaCentavos = (int) ($deudasPorDocumento[$previoId] ?? 0);

            if (Money::aCentavos($previo->total) !== $deudaCentavos + $reembolsadoCentavos) {
                throw new DomainException(
                    'El historial postventa no concilia su desglose económico; requiere conciliación administrativa.'
                );
            }
        }
    }

    /**
     * Lock de caja PRIMERO cuando el plan contiene un componente en efectivo.
     * Revalida server-side la relación sesión-caja (B14.3.1): caja existe,
     * caja ACTIVA y caja.usuario_asignado_id === operador.
     */
    private function bloquearSesionCajaCxC(bool $requiereEfectivo): ?SesionCaja
    {
        if (! $requiereEfectivo) {
            return null;
        }

        $sesion = SesionCaja::query()
            ->lockForUpdate()
            ->with('caja')
            ->where('user_id_apertura', Auth::id())
            ->abiertas()
            ->first();

        if (! $sesion instanceof SesionCaja) {
            throw new DomainException(
                'Debes abrir una caja para realizar el componente de reembolso en efectivo.'
            );
        }

        $caja = $sesion->caja;

        if (! $caja instanceof Caja) {
            throw new DomainException('La caja de tu sesión no existe; contacta al administrador.');
        }

        if (! $caja->activa) {
            throw new DomainException('La caja de tu sesión está inactiva; contacta al administrador.');
        }

        if ((int) $caja->usuario_asignado_id !== (int) Auth::id()) {
            throw new DomainException('La caja de tu sesión no coincide con tu operador asignado.');
        }

        return $sesion;
    }

    private function cancelarConCredito(
        Venta $venta,
        string $motivo,
        array $referenciasReembolso,
        array $referenciasReembolsoCxc
    ): DocumentoPostventa {
        $importeDocumentoCentavos = Money::aCentavos($venta->total);
        $pre = $this->lecturaEconomicaCxc($venta);
        $cuentaId = (int) $pre['cuenta']->id;

        $planPreflight = $this->planearReembolsoConCxc(
            $importeDocumentoCentavos,
            $pre['cuenta'],
            $pre['pagos'],
            $this->abonosDisponibles($pre['abonos'], $pre['reversados']),
            $pre['yaPago'],
            $pre['yaAbono']
        );

        $huellaPreflight = $this->huellaEconomicaCxc(
            importeDocumentoCentavos: $importeDocumentoCentavos,
            venta: $venta,
            cuenta: $pre['cuenta'],
            pagos: $pre['pagos'],
            abonos: $pre['abonos'],
            reversados: $pre['reversados'],
            yaPago: $pre['yaPago'],
            yaAbono: $pre['yaAbono']
        );

        $requiereCaja = $planPreflight['efectivo_centavos'] > 0;

        return DB::transaction(function () use (
            $venta,
            $motivo,
            $referenciasReembolso,
            $referenciasReembolsoCxc,
            $importeDocumentoCentavos,
            $cuentaId,
            $huellaPreflight,
            $requiereCaja
        ): DocumentoPostventa {
            $sesion = $this->bloquearSesionCajaCxC($requiereCaja);

            $economia = $this->bloquearEconomiaConCxc(
                $venta,
                $cuentaId,
                false
            );

            $venta = $economia['venta'];
            $cuenta = $economia['cuenta'];

            $firma = $this->huellaEconomicaCxc(
                importeDocumentoCentavos: $importeDocumentoCentavos,
                venta: $venta,
                cuenta: $cuenta,
                pagos: $economia['pagos'],
                abonos: $economia['abonos'],
                reversados: $economia['reversados'],
                yaPago: $economia['yaPago'],
                yaAbono: $economia['yaAbono']
            );

            if ($firma !== $huellaPreflight) {
                throw new DomainException(
                    'La situación económica de la venta cambió durante la operación. Recarga e inténtalo nuevamente.'
                );
            }

            $plan = $this->planearReembolsoConCxc(
                $importeDocumentoCentavos,
                $cuenta,
                $economia['pagos'],
                $this->abonosDisponibles($economia['abonos'], $economia['reversados']),
                $economia['yaPago'],
                $economia['yaAbono']
            );

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

            $documento = DocumentoPostventa::create([
                'venta_id' => $venta->id,
                'tipo' => DocumentoPostventa::TIPO_CANCELACION,
                'user_id' => Auth::id(),
                'motivo' => trim($motivo),
                'forma_reembolso' => $plan['forma'],
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

            $this->registrarEconomiaConCredito(
                venta: $venta,
                documento: $documento,
                cuenta: $cuenta,
                pagos: $economia['pagos'],
                abonos: $economia['abonos'],
                plan: $plan,
                sesion: $sesion,
                referenciasReembolso: $referenciasReembolso,
                referenciasReembolsoCxc: $referenciasReembolsoCxc,
                importeDocumentoCentavos: $importeDocumentoCentavos
            );

            return $documento->fresh([
                'detalles',
                'reembolsos',
                'reembolsos.pagoVenta',
                'reembolsos.movimientoCxC',
            ]);
        });
    }

    private function devolverConCredito(
        Venta $venta,
        array $ids,
        string $motivo,
        array $referenciasReembolso,
        array $referenciasReembolsoCxc
    ): DocumentoPostventa {
        $detallesPreflight = VentaDetalle::query()
            ->whereIn('id', $ids)
            ->where('venta_id', $venta->id)
            ->get();

        $importeDocumentoCentavos = 0;

        foreach ($detallesPreflight as $detalle) {
            $importeDocumentoCentavos += Money::aCentavos($detalle->precio);
        }

        $pre = $this->lecturaEconomicaCxc($venta);
        $cuentaId = (int) $pre['cuenta']->id;

        $planPreflight = $this->planearReembolsoConCxc(
            $importeDocumentoCentavos,
            $pre['cuenta'],
            $pre['pagos'],
            $this->abonosDisponibles($pre['abonos'], $pre['reversados']),
            $pre['yaPago'],
            $pre['yaAbono']
        );

        $huellaPreflight = $this->huellaEconomicaCxc(
            importeDocumentoCentavos: $importeDocumentoCentavos,
            venta: $venta,
            cuenta: $pre['cuenta'],
            pagos: $pre['pagos'],
            abonos: $pre['abonos'],
            reversados: $pre['reversados'],
            yaPago: $pre['yaPago'],
            yaAbono: $pre['yaAbono']
        );

        $requiereCaja = $planPreflight['efectivo_centavos'] > 0;

        return DB::transaction(function () use (
            $venta,
            $ids,
            $motivo,
            $referenciasReembolso,
            $referenciasReembolsoCxc,
            $importeDocumentoCentavos,
            $cuentaId,
            $huellaPreflight,
            $requiereCaja
        ): DocumentoPostventa {
            $sesion = $this->bloquearSesionCajaCxC($requiereCaja);

            $economia = $this->bloquearEconomiaConCxc(
                $venta,
                $cuentaId,
                true
            );

            $venta = $economia['venta'];
            $cuenta = $economia['cuenta'];

            $firma = $this->huellaEconomicaCxc(
                importeDocumentoCentavos: $importeDocumentoCentavos,
                venta: $venta,
                cuenta: $cuenta,
                pagos: $economia['pagos'],
                abonos: $economia['abonos'],
                reversados: $economia['reversados'],
                yaPago: $economia['yaPago'],
                yaAbono: $economia['yaAbono']
            );

            if ($firma !== $huellaPreflight) {
                throw new DomainException(
                    'La situación económica de la venta cambió durante la operación. Recarga e inténtalo nuevamente.'
                );
            }

            $plan = $this->planearReembolsoConCxc(
                $importeDocumentoCentavos,
                $cuenta,
                $economia['pagos'],
                $this->abonosDisponibles($economia['abonos'], $economia['reversados']),
                $economia['yaPago'],
                $economia['yaAbono']
            );

            if (! $venta->esElegibleParaDevolucion()) {
                throw new DomainException(
                    "La venta {$venta->folio} no admite devoluciones en su estado actual ({$venta->estado})."
                );
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

            if ($totalCentavos !== $importeDocumentoCentavos) {
                throw new DomainException(
                    'El importe desglosado de la devolución cambió durante la operación; reintente.'
                );
            }

            $documento = DocumentoPostventa::create([
                'venta_id' => $venta->id,
                'tipo' => DocumentoPostventa::TIPO_DEVOLUCION,
                'user_id' => Auth::id(),
                'motivo' => trim($motivo),
                'forma_reembolso' => $plan['forma'],
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

            $this->registrarEconomiaConCredito(
                venta: $venta,
                documento: $documento,
                cuenta: $cuenta,
                pagos: $economia['pagos'],
                abonos: $economia['abonos'],
                plan: $plan,
                sesion: $sesion,
                referenciasReembolso: $referenciasReembolso,
                referenciasReembolsoCxc: $referenciasReembolsoCxc,
                importeDocumentoCentavos: $importeDocumentoCentavos
            );

            return $documento->fresh([
                'detalles',
                'reembolsos',
                'reembolsos.pagoVenta',
                'reembolsos.movimientoCxC',
            ]);
        });
    }

    /**
     * @param  array<int|string, string|null>  $referenciasReembolso
     * @param  array<int|string, string|null>  $referenciasReembolsoCxc
     */
    private function registrarEconomiaConCredito(
        Venta $venta,
        DocumentoPostventa $documento,
        CuentaPorCobrar $cuenta,
        Collection $pagos,
        Collection $abonos,
        array $plan,
        ?SesionCaja $sesion,
        array $referenciasReembolso,
        array $referenciasReembolsoCxc,
        int $importeDocumentoCentavos
    ): void {
        $reduccionDeuda = $plan['reduccion_deuda_centavos'];
        $saldoAntes = (int) $cuenta->saldo_centavos;

        if ($reduccionDeuda > 0) {
            if ($documento->tipo === DocumentoPostventa::TIPO_CANCELACION) {
                if ($saldoAntes !== $reduccionDeuda) {
                    throw new DomainException(
                        'El saldo por cobrar no coincide con la cancelación planeada; operación abortada.'
                    );
                }

                $saldoDespues = 0;
                $estado = CuentaPorCobrar::ESTADO_CANCELADA;
                $tipo = MovimientoCxC::TIPO_CANCELACION;
            } else {
                if ($reduccionDeuda > $saldoAntes) {
                    throw new DomainException(
                        'La reducción planeada supera el saldo por cobrar actual; operación abortada.'
                    );
                }

                $saldoDespues = $saldoAntes - $reduccionDeuda;
                $estado = CuentaPorCobrar::estadoNormalDesdeSaldo(
                    (int) $cuenta->importe_original_centavos,
                    $saldoDespues
                );
                $tipo = MovimientoCxC::TIPO_REDUCCION_POSTVENTA;
            }

            MovimientoCxC::create([
                'cuenta_por_cobrar_id' => $cuenta->id,
                'user_id' => Auth::id(),
                'tipo' => $tipo,
                'monto_centavos' => $reduccionDeuda,
                'saldo_antes_centavos' => $saldoAntes,
                'saldo_despues_centavos' => $saldoDespues,
                'metodo' => null,
                'referencia' => null,
                'movimiento_origen_id' => null,
                'documento_postventa_id' => $documento->id,
                'observaciones' => $documento->tipo === DocumentoPostventa::TIPO_CANCELACION
                    ? "Cancelación de venta {$venta->folio} ({$documento->folio})"
                    : "Devolución de venta {$venta->folio} ({$documento->folio})",
            ]);

            $cuenta->update([
                'saldo_centavos' => $saldoDespues,
                'estado' => $estado,
            ]);
        }

        $efectivoCentavos = $plan['efectivo_centavos'];

        if ($efectivoCentavos > 0) {
            if (! $sesion instanceof SesionCaja) {
                throw new DomainException(
                    'Debes abrir una caja para realizar el componente de reembolso en efectivo.'
                );
            }

            if (app(CajaService::class)->calcularEfectivoEsperado($sesion) < $efectivoCentavos) {
                throw new DomainException(
                    'El efectivo disponible en caja es insuficiente para el componente de reembolso.'
                );
            }
        }

        $abonosPorId = $abonos->keyBy('id');
        $pagosPorId = $pagos->keyBy('id');
        $ordenSiguiente = 1;

        foreach ($plan['abonos'] as $movimientoCxcId => $montoCentavos) {
            $abono = $abonosPorId->get($movimientoCxcId);

            if (! $abono instanceof MovimientoCxC) {
                throw new DomainException(
                    'No fue posible relacionar el reembolso con su abono por cobrar original.'
                );
            }

            $referencia = $this->referenciaParaAbono(
                $abono,
                $referenciasReembolsoCxc
            );

            ReembolsoPostventa::create([
                'documento_postventa_id' => $documento->id,
                'pago_venta_id' => null,
                'movimiento_cxc_id' => $abono->id,
                'sesion_caja_id' => $abono->metodo === MovimientoCxC::METODO_EFECTIVO
                    ? $sesion?->id
                    : null,
                'user_id' => Auth::id(),
                'metodo' => $abono->metodo,
                'monto' => Money::aPrecio($montoCentavos),
                'referencia' => $referencia,
                'origen' => ReembolsoPostventa::ORIGEN_CXC_ABONO,
                'orden' => $ordenSiguiente++,
            ]);

            if ($abono->metodo === MovimientoCxC::METODO_EFECTIVO) {
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
                        'documento_postventa_id' => $documento->id,
                    ],
                    "Reembolso CxC en efectivo {$venta->folio} ({$documento->folio})"
                );
            }
        }

        foreach ($plan['pagos'] as $pagoId => $montoCentavos) {
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

            ReembolsoPostventa::create([
                'documento_postventa_id' => $documento->id,
                'pago_venta_id' => $pago->id,
                'movimiento_cxc_id' => null,
                'sesion_caja_id' => $pago->esEfectivo()
                    ? $sesion?->id
                    : null,
                'user_id' => Auth::id(),
                'metodo' => $pago->metodo,
                'monto' => Money::aPrecio($montoCentavos),
                'referencia' => $referencia,
                'origen' => ReembolsoPostventa::ORIGEN_AUTOMATICO,
                'orden' => $ordenSiguiente++,
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
        }

        $sumaDesglose = $reduccionDeuda
            + array_sum(array_map('intval', $plan['abonos']))
            + array_sum(array_map('intval', $plan['pagos']));

        if ($sumaDesglose !== $importeDocumentoCentavos) {
            throw new DomainException(
                'El desglose de deuda y reembolsos no coincide con el importe del documento postventa.'
            );
        }
    }

    /**
     * @param  array<int|string, string|null>  $referenciasReembolsoCxc
     */
    private function referenciaParaAbono(
        MovimientoCxC $abono,
        array $referenciasReembolsoCxc
    ): ?string {
        if ($abono->metodo === MovimientoCxC::METODO_EFECTIVO) {
            return null;
        }

        return $this->validarReferenciaElectronica(
            $abono->metodo,
            $referenciasReembolsoCxc[$abono->id] ?? null
        );
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
