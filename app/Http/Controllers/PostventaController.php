<?php

namespace App\Http\Controllers;

use App\Models\DocumentoPostventa;
use App\Models\PagoVenta;
use App\Models\Venta;
use App\Services\PostventaService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PostventaController extends Controller
{
    public function __construct(
        private readonly PostventaService $service
    ) {}

    private function esReembolsoAutomatico(Venta $venta): bool
    {
        $venta->loadMissing('pagos');

        if ($venta->pagos->isEmpty()) {
            return false;
        }

        $origenes = $venta->pagos
            ->pluck('origen')
            ->unique()
            ->values();

        return $origenes->count() === 1
            && $origenes->first() === PagoVenta::ORIGEN_POS;
    }

    /**
     * Compatibilidad B14.2:
     * distingue fallos financieros del reembolso de los errores normales
     * de dominio de cancelación/devolución.
     *
     * Cuando estabilicemos B14.2 esto puede evolucionar a excepciones de
     * dominio especializadas, pero centralizamos aquí la clasificación para
     * no dispersarla por el controlador.
     */
    private function esErrorFinancieroPostventa(DomainException $e): bool
    {
        $mensaje = mb_strtolower($e->getMessage());

        foreach ([
            'reembolso',
            'caja',
            'pago',
            'referencia',
            'concili',
            'prorrateo',
            'composición',
            'composicion',
            'saldo económico',
            'saldo economico',
            'deuda',
            'económica',
            'economica',
            'abono',
            'cambió',
        ] as $indicador) {
            if (str_contains($mensaje, $indicador)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compatibilidad temporal con la vista de cancelación B13/B14.
     *
     * En B14.2 las ventas modernas dejarán de elegir manualmente la forma;
     * mientras cambiamos la vista mantenemos esta variable disponible.
     */
    private function formaReembolsoSugerida(Venta $venta): string
    {
        $venta->loadMissing('pagos');

        $metodos = $venta->pagos
            ->pluck('metodo')
            ->unique()
            ->values();

        if (
            $metodos->count() === 1
            && in_array($metodos->first(), PagoVenta::METODOS, true)
        ) {
            return $metodos->first();
        }

        return DocumentoPostventa::FORMA_EFECTIVO;
    }

    /**
     * Datos monetarios seguros para presentar/prorratear reembolsos en UI.
     * El navegador solo PREVISUALIZA; PostventaService sigue siendo la
     * autoridad y recalcula todo server-side.
     *
     * @return array<int, array{
     *     id:int,
     *     metodo:string,
     *     monto_centavos:int,
     *     ya_reembolsado_centavos:int,
     *     orden:int
     * }>
     */
    private function pagosReembolsoUi(Venta $venta): array
    {
        $venta->loadMissing('pagos.reembolsos');

        return $venta->pagos
            ->map(function (PagoVenta $pago): array {
                $yaReembolsado = 0;

                foreach ($pago->reembolsos as $reembolso) {
                    $yaReembolsado += \App\Support\Money::aCentavos(
                        $reembolso->monto
                    );
                }

                return [
                    'id' => (int) $pago->id,
                    'metodo' => $pago->metodo,
                    'monto_centavos' => \App\Support\Money::aCentavos(
                        $pago->monto_aplicado
                    ),
                    'ya_reembolsado_centavos' => $yaReembolsado,
                    'orden' => (int) $pago->orden,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * ABONOS CxC reembolsables (LIFO por inserción) para previsualizar el
     * componente deudor en la UI. El navegador solo PREVISUALIZA; el servicio
     * recalcula todo server-side bajo locks.
     *
     * @return array<int, array{
     *     id:int,
     *     metodo:string,
     *     monto_centavos:int,
     *     ya_reembolsado_centavos:int,
     *     disponible_centavos:int
     * }>
     */
    private function abonosReembolsoUi(Venta $venta): array
    {
        $cuenta = $venta->cuentaPorCobrar;

        if (! $cuenta instanceof \App\Models\CuentaPorCobrar) {
            return [];
        }

        $abonos = \App\Models\MovimientoCxC::query()
            ->where('cuenta_por_cobrar_id', $cuenta->id)
            ->where('tipo', \App\Models\MovimientoCxC::TIPO_ABONO)
            ->with('reembolsosPostventa')
            ->orderByDesc('id')
            ->get();

        if ($abonos->isEmpty()) {
            return [];
        }

        $reversados = \App\Models\MovimientoCxC::query()
            ->where('tipo', \App\Models\MovimientoCxC::TIPO_REVERSA_ABONO)
            ->whereIn('movimiento_origen_id', $abonos->pluck('id'))
            ->pluck('movimiento_origen_id')
            ->all();

        $resultado = [];

        foreach ($abonos as $abono) {
            if (in_array((int) $abono->id, $reversados, true)) {
                continue;
            }

            $yaReembolsado = 0;

            foreach ($abono->reembolsosPostventa as $reembolso) {
                $yaReembolsado += \App\Support\Money::aCentavos($reembolso->monto);
            }

            $resultado[] = [
                'id' => (int) $abono->id,
                'metodo' => $abono->metodo,
                'monto_centavos' => (int) $abono->monto_centavos,
                'ya_reembolsado_centavos' => $yaReembolsado,
                'disponible_centavos' => (int) $abono->monto_centavos - $yaReembolsado,
            ];
        }

        return $resultado;
    }

    public function cancelarForm(Venta $venta)
    {
        abort_unless(
            $venta->esElegibleParaCancelacion(),
            409,
            'Esta venta no puede cancelarse.'
        );

        $venta->load([
            'user',
            'detalles.item',
            'detalles.item.categoria',
            'pagos',
            'cuentaPorCobrar',
        ]);

        return view('postventa.cancelar', [
            'venta' => $venta,
            'totalFormateado' => \App\Support\Money::formatear(
                (string) $venta->total
            ),
            'formasReembolso' => DocumentoPostventa::FORMAS_REEMBOLSO,
            'sugerido' => $this->formaReembolsoSugerida($venta),
            'reembolsoAutomatico' => $this->esReembolsoAutomatico($venta),
            'pagosReembolsoUi' => $this->pagosReembolsoUi($venta),
            'creditoPostventa' => $venta->cuentaPorCobrar !== null,
            'abonosReembolsoUi' => $this->abonosReembolsoUi($venta),
        ]);
    }

    public function cancelar(Request $request, Venta $venta)
    {
        $venta->loadMissing(['pagos', 'cuentaPorCobrar']);
        $esCredito = $venta->cuentaPorCobrar !== null;
        $automatico = $esCredito || $this->esReembolsoAutomatico($venta);

        $rules = [
            'motivo' => ['required', 'string', 'min:5', 'max:2000'],
            'referencias_reembolso' => ['nullable', 'array'],
            'referencias_reembolso.*' => ['nullable', 'string', 'max:100'],
            'referencias_reembolso_cxc' => ['nullable', 'array'],
            'referencias_reembolso_cxc.*' => ['nullable', 'string', 'max:100'],
            'referencia_reembolso' => ['nullable', 'string', 'max:100'],
        ];

        if ($automatico) {
            $rules['forma_reembolso'] = ['nullable'];
        } else {
            $rules['forma_reembolso'] = [
                'required',
                Rule::in(DocumentoPostventa::FORMAS_REEMBOLSO),
            ];
        }

        $data = $request->validate($rules);

        try {
            $documento = $this->service->cancelar(
                $venta,
                $data['motivo'],
                $automatico
                    ? null
                    : ($data['forma_reembolso'] ?? null),
                $data['referencias_reembolso'] ?? [],
                $data['referencia_reembolso'] ?? null,
                $data['referencias_reembolso_cxc'] ?? []
            );
        } catch (DomainException $e) {
            $campo = $this->esErrorFinancieroPostventa($e)
                ? 'reembolso'
                : 'motivo';

            throw ValidationException::withMessages([
                $campo => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('postventa.show', $documento)
            ->with(
                'success',
                "Venta {$venta->folio} cancelada (documento {$documento->folio})."
            );
    }

    public function devolverForm(Venta $venta)
    {
        abort_unless(
            $venta->esElegibleParaDevolucion(),
            409,
            'Esta venta no admite devoluciones.'
        );

        $venta->load([
            'user',
            'pagos',
            'detalles.item',
            'detalles.item.categoria',
            'detalles.documentoPostventaDetalle.documento',
            'cuentaPorCobrar',
        ]);

        return view('postventa.devolver', [
            'venta' => $venta,
            'formasReembolso' => DocumentoPostventa::FORMAS_REEMBOLSO,
            'reembolsoAutomatico' => $this->esReembolsoAutomatico($venta),
            'pagosReembolsoUi' => $this->pagosReembolsoUi($venta),
            'creditoPostventa' => $venta->cuentaPorCobrar !== null,
            'abonosReembolsoUi' => $this->abonosReembolsoUi($venta),
        ]);
    }

    public function devolver(Request $request, Venta $venta)
    {
        $venta->loadMissing(['pagos', 'cuentaPorCobrar']);
        $esCredito = $venta->cuentaPorCobrar !== null;
        $automatico = $esCredito || $this->esReembolsoAutomatico($venta);

        $rules = [
            'motivo' => ['required', 'string', 'min:3', 'max:2000'],
            'detalles' => [
                'required',
                'array',
                'min:1',
                'max:'.$venta->detalles()->count(),
            ],
            'detalles.*' => ['required', 'integer', 'distinct'],
            'referencias_reembolso' => ['nullable', 'array'],
            'referencias_reembolso.*' => ['nullable', 'string', 'max:100'],
            'referencias_reembolso_cxc' => ['nullable', 'array'],
            'referencias_reembolso_cxc.*' => ['nullable', 'string', 'max:100'],
            'referencia_reembolso' => ['nullable', 'string', 'max:100'],
        ];

        if ($automatico) {
            $rules['forma_reembolso'] = ['nullable'];
        } else {
            $rules['forma_reembolso'] = [
                'required',
                Rule::in(DocumentoPostventa::FORMAS_REEMBOLSO),
            ];
        }

        $data = $request->validate($rules);

        try {
            $documento = $this->service->devolver(
                $venta,
                $data['detalles'],
                $data['motivo'],
                $automatico
                    ? null
                    : ($data['forma_reembolso'] ?? null),
                $data['referencias_reembolso'] ?? [],
                $data['referencia_reembolso'] ?? null,
                $data['referencias_reembolso_cxc'] ?? []
            );
        } catch (DomainException $e) {
            $campo = $this->esErrorFinancieroPostventa($e)
                ? 'reembolso'
                : 'detalles';

            throw ValidationException::withMessages([
                $campo => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('postventa.show', $documento)
            ->with(
                'success',
                "Devolución registrada (documento {$documento->folio}). Los artículos recibidos quedan pendientes de revisión antes de poder volver a venderse."
            )
            ->with('pendientesRevision', true);
    }

    public function show(DocumentoPostventa $documento)
    {
        $documento->load([
            'user',
            'venta.user',
            'detalles.item',
            'detalles.item.categoria',
            'detalles.ventaDetalle',
            'reembolsos',
            'reembolsos.pagoVenta',
            'reembolsos.movimientoCxC',
            'reembolsos.movimientoCxC.cuentaPorCobrar',
            'movimientoCxCDeuda',
        ]);

        return view('postventa.show', [
            'documento' => $documento,
        ]);
    }

    public function print(DocumentoPostventa $documento)
    {
        $documento->load([
            'user',
            'venta.user',
            'detalles.item',
            'detalles.item.categoria',
            'reembolsos',
            'reembolsos.pagoVenta',
            'reembolsos.movimientoCxC',
            'reembolsos.movimientoCxC.cuentaPorCobrar',
            'movimientoCxCDeuda',
        ]);

        return view('postventa.print', [
            'documento' => $documento,
        ]);
    }
}
