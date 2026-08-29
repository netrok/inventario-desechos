<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUbicacionRequest;
use App\Http\Requests\UpdateUbicacionRequest;
use App\Models\Item;
use App\Models\Ubicacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UbicacionController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $ubicaciones = Ubicacion::query()
            ->when($q !== '', fn ($qq) => $qq->where('nombre', 'ilike', "%{$q}%"))
            ->withCount('items') // items_count para la vista
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        return view('ubicaciones.index', compact('ubicaciones', 'q'));
    }

    public function create()
    {
        return view('ubicaciones.create');
    }

    public function store(StoreUbicacionRequest $request)
    {
        $data = $request->validated();

        // Si existe la columna "activo", la respetamos; si no, no inventamos.
        if (Schema::hasColumn('ubicaciones', 'activo')) {
            $data['activo'] = (bool) $request->boolean('activo');
        }

        Ubicacion::create($data);

        return redirect()
            ->route('ubicaciones.index')
            ->with('success', 'Ubicación creada.');
    }

    public function edit(Ubicacion $ubicacion)
    {
        return view('ubicaciones.edit', compact('ubicacion'));
    }

    public function update(UpdateUbicacionRequest $request, Ubicacion $ubicacion)
    {
        $data = $request->validated();

        if (Schema::hasColumn('ubicaciones', 'activo')) {
            $data['activo'] = (bool) $request->boolean('activo');
        }

        $ubicacion->update($data);

        return redirect()
            ->route('ubicaciones.index')
            ->with('success', 'Ubicación actualizada.');
    }

    public function destroy(Ubicacion $ubicacion)
    {
        return DB::transaction(function () use ($ubicacion) {
            // Conservar nombres de ubicaciones históricas: no borrar si hay
            // items actuales, items soft-deleted legacy o movimientos de referencia.
            $itemsActuales = $ubicacion->items()->exists();
            $itemsLegacy = Item::withTrashed()
                ->where('ubicacion_id', $ubicacion->id)
                ->exists();
            $movimientos = $ubicacion->movimientosOrigen()->exists()
                || $ubicacion->movimientosDestino()->exists();

            if ($itemsActuales || $itemsLegacy) {
                return back()->with('error', 'No puedes eliminar esta ubicación porque tiene items asignados (incluidos históricos).');
            }

            if ($movimientos) {
                return back()->with('error', 'No puedes eliminar esta ubicación porque es referencia de movimientos históricos.');
            }

            $ubicacion->delete();

            return redirect()
                ->route('ubicaciones.index')
                ->with('success', 'Ubicación eliminada.');
        });
    }
}
