<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUbicacionRequest;
use App\Http\Requests\UpdateUbicacionRequest;
use App\Models\Ubicacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UbicacionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:ubicaciones.ver')->only(['index']);
        $this->middleware('permission:ubicaciones.crear')->only(['create', 'store']);
        $this->middleware('permission:ubicaciones.editar')->only(['edit', 'update']);
        $this->middleware('permission:ubicaciones.eliminar')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $ubicaciones = Ubicacion::query()
            ->when($q !== '', fn ($qq) => $qq->where('nombre', 'ilike', "%{$q}%"))
            ->withCount('items')
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

        // ✅ Solo si la columna existe (evita tu error actual en PG)
        if (Schema::hasColumn('ubicaciones', 'activo')) {
            $data['activo'] = $request->boolean('activo');
        }

        Ubicacion::create($data);

        return redirect()
            ->route('ubicaciones.index')
            ->with('ok', 'Ubicación creada.');
    }

    public function edit(Ubicacion $ubicacion)
    {
        return view('ubicaciones.edit', compact('ubicacion'));
    }

    public function update(UpdateUbicacionRequest $request, Ubicacion $ubicacion)
    {
        $data = $request->validated();

        if (Schema::hasColumn('ubicaciones', 'activo')) {
            $data['activo'] = $request->boolean('activo');
        }

        $ubicacion->update($data);

        return redirect()
            ->route('ubicaciones.index')
            ->with('ok', 'Ubicación actualizada.');
    }

    public function destroy(Ubicacion $ubicacion)
    {
        return DB::transaction(function () use ($ubicacion) {
            if ($ubicacion->items()->exists()) {
                return back()->with('error', 'No puedes eliminar esta ubicación porque tiene items asignados.');
            }

            $ubicacion->delete();

            return redirect()
                ->route('ubicaciones.index')
                ->with('ok', 'Ubicación eliminada.');
        });
    }
}
