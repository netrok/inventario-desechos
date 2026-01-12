<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\Ubicacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ItemController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:items.ver')->only(['index', 'show']);
        $this->middleware('permission:items.crear')->only(['create', 'store']);
        $this->middleware('permission:items.editar')->only(['edit', 'update']);
        $this->middleware('permission:items.eliminar')->only(['destroy']);

        $this->middleware('permission:items.papelera')->only(['trash']);
        $this->middleware('permission:items.restaurar')->only(['restore']);
        $this->middleware('permission:items.borrar_definitivo')->only(['forceDelete']);

        $this->middleware('permission:items.cambiar_estado')->only(['changeEstado']);
        $this->middleware('permission:items.mover')->only(['moveUbicacion']);
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $estado = $request->get('estado') ?: null;
        $ubicacionId = $request->get('ubicacion_id') ?: null;

        $base = Item::query()
            ->with('ubicacion')
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('codigo', 'ilike', "%{$q}%")
                        ->orWhere('serie', 'ilike', "%{$q}%")
                        ->orWhere('marca', 'ilike', "%{$q}%")
                        ->orWhere('modelo', 'ilike', "%{$q}%");
                });
            })
            ->when($estado, fn ($qq) => $qq->where('estado', $estado))
            ->when($ubicacionId, fn ($qq) => $qq->where('ubicacion_id', $ubicacionId));

        $stats = (clone $base)->selectRaw("
            count(*) as total,
            count(*) filter (where estado='DISPONIBLE') as disponible,
            count(*) filter (where estado='RESERVADO') as reservado,
            count(*) filter (where estado='REPARACION') as reparacion,
            count(*) filter (where estado='VENDIDO') as vendido,
            count(*) filter (where estado='BAJA') as baja
        ")->first();

        $items = (clone $base)
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('items.index', [
            'items' => $items,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'estados' => Item::ESTADOS,
            'filters' => [
                'q' => $q,
                'estado' => $estado,
                'ubicacion_id' => $ubicacionId,
            ],
            'stats' => $stats,
            'trashCount' => Item::onlyTrashed()->count(),
        ]);
    }

    public function create()
    {
        return view('items.create', [
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'estados' => Item::ESTADOS,
        ]);
    }

    public function store(StoreItemRequest $request)
    {
        // codigo lo genera el modelo, foto se procesa aquí
        $data = $request->safe()->except(['codigo', 'foto']);

        if ($request->hasFile('foto')) {
            $data['foto_path'] = $request->file('foto')->store('items', 'public');
        }

        $item = Item::create($data);

        Movimiento::create([
            'item_id' => $item->id,
            'tipo' => 'ALTA',
            'estado_anterior' => null,
            'estado_nuevo' => $item->estado,
            'ubicacion_anterior_id' => null,
            'ubicacion_nueva_id' => $item->ubicacion_id,
            'user_id' => Auth::id(),
            'detalle' => 'Alta de item',
        ]);

        return redirect()->route('items.index')->with('ok', 'Item creado.');
    }

    public function show(Item $item)
    {
        $item->load([
            'ubicacion',
            'movimientos.user',
            'movimientos.ubicacionAnterior',
            'movimientos.ubicacionNueva',
        ]);

        return view('items.show', [
            'item' => $item,
            // ✅ NECESARIO para tu select en show.blade.php
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
        ]);
    }

    public function edit(Item $item)
    {
        return view('items.edit', [
            'item' => $item,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'estados' => Item::ESTADOS,
        ]);
    }

    public function update(UpdateItemRequest $request, Item $item)
    {
        $data = $request->validated();

        // Transición de estado
        $toEstado = $data['estado'] ?? $item->estado;
        if ($item->estado !== $toEstado && !Item::canTransition($item->estado, $toEstado)) {
            return back()->withErrors([
                'estado' => "No se permite cambiar de {$item->estado} a {$toEstado}.",
            ])->withInput();
        }

        $beforeEstado = $item->estado;
        $beforeUbicacion = $item->ubicacion_id;

        // Foto nueva (si viene)
        if ($request->hasFile('foto')) {
            if ($item->foto_path && Storage::disk('public')->exists($item->foto_path)) {
                Storage::disk('public')->delete($item->foto_path);
            }
            $data['foto_path'] = $request->file('foto')->store('items', 'public');
        }

        $item->update($data);

        $changedEstado = $beforeEstado !== $item->estado;
        $changedUbicacion = $beforeUbicacion !== $item->ubicacion_id;

        if ($changedEstado || $changedUbicacion) {
            Movimiento::create([
                'item_id' => $item->id,
                'tipo' => $changedEstado ? 'CAMBIO_ESTADO' : 'CAMBIO_UBICACION',
                'estado_anterior' => $beforeEstado,
                'estado_nuevo' => $item->estado,
                'ubicacion_anterior_id' => $beforeUbicacion,
                'ubicacion_nueva_id' => $item->ubicacion_id,
                'user_id' => Auth::id(),
                'detalle' => 'Actualización de item',
            ]);
        }

        return redirect()->route('items.show', $item)->with('ok', 'Item actualizado.');
    }

    public function changeEstado(Request $request, $id)
    {
        $item = Item::findOrFail($id);

        $data = $request->validate([
            'estado' => ['required', Rule::in(Item::ESTADOS)],
            'detalle' => ['nullable', 'string', 'max:500'],
        ]);

        $toEstado = $data['estado'];

        if ($item->estado !== $toEstado && !Item::canTransition($item->estado, $toEstado)) {
            return back()->withErrors([
                'estado' => "No se permite cambiar de {$item->estado} a {$toEstado}.",
            ])->withInput();
        }

        $beforeEstado = $item->estado;

        if ($beforeEstado !== $toEstado) {
            $item->estado = $toEstado;
            $item->save();

            Movimiento::create([
                'item_id' => $item->id,
                'tipo' => 'CAMBIO_ESTADO',
                'estado_anterior' => $beforeEstado,
                'estado_nuevo' => $toEstado,
                'ubicacion_anterior_id' => $item->ubicacion_id,
                'ubicacion_nueva_id' => $item->ubicacion_id,
                'user_id' => Auth::id(),
                'detalle' => $data['detalle'] ?? 'Cambio rápido de estado',
            ]);
        }

        return back()->with('ok', "Estado actualizado a {$toEstado}.");
    }

    public function moveUbicacion(Request $request, $id)
    {
        $item = Item::findOrFail($id);

        $data = $request->validate([
            'ubicacion_id' => ['nullable', 'exists:ubicaciones,id'],
            'detalle' => ['nullable', 'string', 'max:500'],
        ]);

        $beforeUbicacion = $item->ubicacion_id;
        $toUbicacion = $data['ubicacion_id'] ?? null;

        if ((string) $beforeUbicacion !== (string) $toUbicacion) {
            $item->ubicacion_id = $toUbicacion;
            $item->save();

            Movimiento::create([
                'item_id' => $item->id,
                'tipo' => 'CAMBIO_UBICACION',
                'estado_anterior' => $item->estado,
                'estado_nuevo' => $item->estado,
                'ubicacion_anterior_id' => $beforeUbicacion,
                'ubicacion_nueva_id' => $toUbicacion,
                'user_id' => Auth::id(),
                'detalle' => $data['detalle'] ?? 'Movimiento rápido de ubicación',
            ]);
        }

        return back()->with('ok', 'Ubicación actualizada.');
    }

    public function trash(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $items = Item::onlyTrashed()
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('codigo', 'ilike', "%{$q}%")
                        ->orWhere('serie', 'ilike', "%{$q}%");
                });
            })
            ->orderByDesc('deleted_at')
            ->paginate(15)
            ->withQueryString();

        return view('items.trash', [
            'items' => $items,
            'q' => $q,
        ]);
    }

    public function restore($id)
    {
        $item = Item::onlyTrashed()->findOrFail($id);
        $item->restore();

        Movimiento::create([
            'item_id' => $item->id,
            'tipo' => 'RESTAURAR',
            'estado_anterior' => null,
            'estado_nuevo' => $item->estado,
            'ubicacion_anterior_id' => null,
            'ubicacion_nueva_id' => $item->ubicacion_id,
            'user_id' => Auth::id(),
            'detalle' => 'Item restaurado desde papelera',
        ]);

        return redirect()->route('items.trash')->with('ok', 'Item restaurado.');
    }

    public function forceDelete($id)
    {
        $item = Item::onlyTrashed()->findOrFail($id);

        if ($item->foto_path && Storage::disk('public')->exists($item->foto_path)) {
            Storage::disk('public')->delete($item->foto_path);
        }

        $item->forceDelete();

        return redirect()->route('items.trash')->with('ok', 'Item eliminado permanentemente.');
    }

    public function destroy(Item $item)
    {
        $item->delete();

        return redirect()->route('items.index')->with('ok', 'Item enviado a papelera.');
    }
}
