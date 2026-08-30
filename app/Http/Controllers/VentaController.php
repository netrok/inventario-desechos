<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Support\Money;
use Illuminate\Http\Request;

class VentaController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'folio' => trim((string) $request->query('folio', '')),
            'cliente' => trim((string) $request->query('cliente', '')),
            'desde' => $request->query('desde') ?: null,
            'hasta' => $request->query('hasta') ?: null,
            'estado' => $request->query('estado') ?: null,
        ];

        $ventas = Venta::query()
            ->with('user')
            ->withCount('detalles')
            ->when($filters['folio'] !== '', fn ($q) => $q->where('folio', 'ilike', "%{$filters['folio']}%"))
            ->when($filters['cliente'] !== '', function ($q) use ($filters) {
                $q->where(function ($w) use ($filters) {
                    $w->where('cliente_nombre', 'ilike', "%{$filters['cliente']}%")
                        ->orWhere('cliente_codigo', 'ilike', "%{$filters['cliente']}%");
                });
            })
            ->when($filters['desde'], fn ($q) => $q->whereDate('created_at', '>=', $filters['desde']))
            ->when($filters['hasta'], fn ($q) => $q->whereDate('created_at', '<=', $filters['hasta']))
            ->when($filters['estado'], fn ($q) => $q->where('estado', $filters['estado']))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('ventas.index', [
            'ventas' => $ventas,
            'filters' => $filters,
            'estados' => Venta::ESTADOS,
        ]);
    }

    public function show(Venta $venta)
    {
        $venta->load([
            'user',
            'detalles.item',
            'detalles.item.categoria',
            'documentosPostventa.user',
            'documentosPostventa.detalles.item',
        ]);

        return view('ventas.show', ['venta' => $venta]);
    }

    /**
     * Ticket térmico imprimible (80/58mm). Solo lectura de una Venta ya
     * confirmada: NO muta Venta/Item, NO crea movimientos ni registros.
     *
     * El ancho sale de la configuración por defecto; se puede sobreescribir
     * manualmente con ?width=58|80 (validado estrictamente). Nunca se interpola
     * en CSS un valor distinto de 58/80.
     */
    public function ticket(Request $request, Venta $venta)
    {
        $venta->load([
            'user',
            'detalles.item',
            'detalles.item.categoria',
            'pagos',
        ]);

        $defaultWidth = Configuracion::ticketAncho();
        $requested = $request->integer('width', $defaultWidth);
        // Únicos anchos soportados; cualquier otro valor cae al default configurado.
        $width = in_array($requested, Configuracion::ANCHOS_VALIDOS, true) ? $requested : $defaultWidth;

        // Autoprint: sólo se activa por flag explícito (no por query arbitraria).
        $autoprint = $request->boolean('autoprint') && Configuracion::ticketAutoprint();

        return view('ventas.ticket', [
            'venta' => $venta,
            'width' => $width,
            'autoprint' => $autoprint,
            'configuracion' => $this->configuracionSegura(),
            'totalFormateado' => Money::formatear((string) $venta->total),
            'preciosFormateados' => $venta->detalles->mapWithKeys(
                fn (VentaDetalle $detalle) => [
                    $detalle->id => Money::formatear((string) $detalle->precio),
                ]
            ),
        ]);
    }

    /**
     * Datos de configuración para el ticket (identidad + pie), con fallback
     * seguro si no existe fila, y SIN secretos.
     */
    private function configuracionSegura(): array
    {
        try {
            $cfg = Configuracion::obtener();

            return [
                'empresa_nombre' => $cfg->empresa_nombre,
                'empresa_rfc' => $cfg->empresa_rfc,
                'empresa_telefono' => $cfg->empresa_telefono,
                'empresa_email' => $cfg->empresa_email,
                'empresa_direccion' => $cfg->empresa_direccion,
                'ticket_pie' => $cfg->ticket_pie,
            ];
        } catch (\Throwable) {
            return [
                'empresa_nombre' => config('app.name', 'Inventario ReUse'),
                'empresa_rfc' => null,
                'empresa_telefono' => null,
                'empresa_email' => null,
                'empresa_direccion' => null,
                'ticket_pie' => null,
            ];
        }
    }
}
