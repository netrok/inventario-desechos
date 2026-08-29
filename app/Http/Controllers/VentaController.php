<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Support\Money;
use Illuminate\Http\Request;

class VentaController extends Controller
{
    public function index()
    {
        $ventas = Venta::query()
            ->with('user')
            ->withCount('detalles')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('ventas.index', ['ventas' => $ventas]);
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
     */
    public function ticket(Request $request, Venta $venta)
    {
        $venta->load([
            'user',
            'detalles.item',
            'detalles.item.categoria',
        ]);

        // Únicos anchos soportados; cualquier otro valor cae a 80.
        // Nunca se interpola en CSS un valor distinto de 58/80.
        $width = $request->integer('width', 80) === 58 ? 58 : 80;

        return view('ventas.ticket', [
            'venta' => $venta,
            'width' => $width,
            'totalFormateado' => Money::formatear((string) $venta->total),
            'preciosFormateados' => $venta->detalles->mapWithKeys(
                fn (VentaDetalle $detalle) => [
                    $detalle->id => Money::formatear((string) $detalle->precio),
                ]
            ),
        ]);
    }
}
