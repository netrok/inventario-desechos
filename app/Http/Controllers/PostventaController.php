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

    /**
     * Deriva la forma de reembolso sugerida desde los pagos reales de la venta:
     * método único si solo hubo uno; MIXTO o legacy sin pagos → EFECTIVO.
     */
    private function formaReembolsoSugerida(Venta $venta): string
    {
        $metodos = $venta->pagos->pluck('metodo')->unique()->values();

        if ($metodos->count() === 1 && in_array($metodos->first(), PagoVenta::METODOS, true)) {
            return $metodos->first();
        }

        return DocumentoPostventa::FORMA_EFECTIVO;
    }

    /**
     * Formulario de cancelación (por permiso ventas.cancelar).
     */
    public function cancelarForm(Venta $venta)
    {
        abort_unless($venta->esElegibleParaCancelacion(), 409, 'Esta venta no puede cancelarse.');

        $venta->load(['user', 'detalles.item', 'detalles.item.categoria', 'pagos']);

        // Sugerencia de reembolso: el mismo método único de pago si existía; si
        // la venta fue mixta o legacy, se sugiere EFECTIVO.
        $sugerido = $this->formaReembolsoSugerida($venta);

        return view('postventa.cancelar', [
            'venta' => $venta,
            'totalFormateado' => \App\Support\Money::formatear((string) $venta->total),
            'formasReembolso' => DocumentoPostventa::FORMAS_REEMBOLSO,
            'sugerido' => $sugerido,
        ]);
    }

    /**
     * Ejecuta la cancelación atómica (por permiso ventas.cancelar).
     */
    public function cancelar(Request $request, Venta $venta)
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:2000'],
            'forma_reembolso' => ['required', Rule::in(DocumentoPostventa::FORMAS_REEMBOLSO)],
        ]);

        try {
            $documento = $this->service->cancelar($venta, $data['motivo'], $data['forma_reembolso']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['motivo' => $e->getMessage()]);
        }

        return redirect()->route('postventa.show', $documento)
            ->with('success', "Venta {$venta->folio} cancelada (documento {$documento->folio}).");
    }

    /**
     * Formulario de devolución (por permiso ventas.devolver).
     */
    public function devolverForm(Venta $venta)
    {
        abort_unless($venta->esElegibleParaDevolucion(), 409, 'Esta venta no admite devoluciones.');

        $venta->load([
            'user',
            'detalles.item',
            'detalles.item.categoria',
            'detalles.documentoPostventaDetalle.documento',
        ]);

        return view('postventa.devolver', [
            'venta' => $venta,
            'formasReembolso' => DocumentoPostventa::FORMAS_REEMBOLSO,
        ]);
    }

    /**
     * Ejecuta la devolución (parcial o total) atómica (por permiso ventas.devolver).
     */
    public function devolver(Request $request, Venta $venta)
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:2000'],
            'forma_reembolso' => ['required', Rule::in(DocumentoPostventa::FORMAS_REEMBOLSO)],
            'detalles' => ['required', 'array', 'min:1', 'max:'.$venta->detalles()->count()],
            'detalles.*' => ['required', 'integer', 'distinct'],
        ]);

        // El importe se ignora deliberadamente: se calcula server-side.
        try {
            $documento = $this->service->devolver(
                $venta,
                $data['detalles'],
                $data['motivo'],
                $data['forma_reembolso']
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['detalles' => $e->getMessage()]);
        }

        return redirect()->route('postventa.show', $documento)
            ->with('success', "Devolución registrada (documento {$documento->folio}). Los artículos recibidos quedan pendientes de revisión antes de poder volver a venderse.")
            ->with('pendientesRevision', true);
    }

    /**
     * Detalle consultable de un documento postventa (por ventas.ver).
     */
    public function show(DocumentoPostventa $documento)
    {
        $documento->load([
            'user',
            'venta.user',
            'detalles.item',
            'detalles.item.categoria',
            'detalles.ventaDetalle',
        ]);

        return view('postventa.show', ['documento' => $documento]);
    }

    /**
     * Comprobante imprimible del documento postventa (por ventas.ver).
     */
    public function print(DocumentoPostventa $documento)
    {
        $documento->load([
            'user',
            'venta.user',
            'detalles.item',
            'detalles.item.categoria',
        ]);

        return view('postventa.print', ['documento' => $documento]);
    }
}
