<?php

namespace App\Http\Controllers;

use App\Models\DocumentoPostventaDetalle;
use App\Models\RevisionDevolucion;
use App\Services\RevisionDevolucionService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RevisionDevolucionController extends Controller
{
    public function __construct(private readonly RevisionDevolucionService $service) {}

    /**
     * Formulario de revisión de un artículo devuelto (B13).
     */
    public function create(DocumentoPostventaDetalle $detalle)
    {
        if ($detalle->item?->estado !== 'DEVUELTO') {
            abort(409, 'El artículo no está DEVUELTO en este momento.');
        }

        $detalle->load([
            'item.categoria',
            'documento.venta.cliente',
            'ventaDetalle',
        ]);

        return view('items.revision', [
            'detalle' => $detalle,
            'resultados' => RevisionDevolucion::RESULTADOS,
        ]);
    }

    /**
     * Ejecuta la revisión de forma atómica vía el servicio.
     */
    public function store(Request $request, DocumentoPostventaDetalle $detalle)
    {
        $data = $request->validate([
            'resultado' => ['required', Rule::in(RevisionDevolucion::RESULTADOS)],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $revision = $this->service->revisar(
                $detalle->id,
                $data['resultado'],
                $data['observaciones'] ?? null,
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['resultado' => $e->getMessage()]);
        }

        $mensajes = [
            RevisionDevolucion::RESULTADO_DISPONIBLE => 'Revisión registrada. El artículo está disponible nuevamente para venta.',
            RevisionDevolucion::RESULTADO_REPARACION => 'Revisión registrada. El artículo fue enviado a reparación.',
            RevisionDevolucion::RESULTADO_BAJA => 'Revisión registrada. El artículo fue dado de baja.',
        ];

        return redirect()
            ->route('items.show', $revision->item_id)
            ->with('success', $mensajes[$data['resultado']]);
    }
}
