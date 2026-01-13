<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Models\Categoria;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\Ubicacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
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
        $categoriaId = $request->get('categoria_id') ?: null;

        $base = Item::query()
            ->with(['ubicacion', 'categoria'])
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('codigo', 'ilike', "%{$q}%")
                        ->orWhere('serie', 'ilike', "%{$q}%")
                        ->orWhere('marca', 'ilike', "%{$q}%")
                        ->orWhere('modelo', 'ilike', "%{$q}%");
                });
            })
            ->when($estado, fn ($qq) => $qq->where('estado', $estado))
            ->when($ubicacionId, fn ($qq) => $qq->where('ubicacion_id', $ubicacionId))
            ->when($categoriaId, fn ($qq) => $qq->where('categoria_id', $categoriaId));

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

        $categoriasQuery = Categoria::query()->orderBy('nombre');
        if (Schema::hasColumn('categorias', 'activo')) {
            $categoriasQuery->where('activo', true);
        }

        return view('items.index', [
            'items' => $items,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'categorias' => $categoriasQuery->get(),
            'estados' => Item::ESTADOS,
            'filters' => [
                'q' => $q,
                'estado' => $estado,
                'ubicacion_id' => $ubicacionId,
                'categoria_id' => $categoriaId,
            ],
            'stats' => $stats,
            'trashCount' => Item::onlyTrashed()->count(),
        ]);
    }

    public function create()
    {
        $categoriasQuery = Categoria::query()->orderBy('nombre');
        if (Schema::hasColumn('categorias', 'activo')) {
            $categoriasQuery->where('activo', true);
        }

        return view('items.create', [
            'item' => null,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'categorias' => $categoriasQuery->get(),
            'estados' => Item::ESTADOS,
        ]);
    }

    public function store(StoreItemRequest $request)
    {
        $data = $request->validated();

        // El modelo genera codigo/codigo_seq, no permitas que te lo pisen
        unset($data['codigo'], $data['codigo_seq']);

        // Ya no existe columna legacy "categoria"
        unset($data['categoria']);

        if ($request->hasFile('foto') && Schema::hasColumn('items', 'foto_path')) {
            $data['foto_path'] = $request->file('foto')->store('items', 'public');
        }
        unset($data['foto']);

        $item = Item::create($data);

        Movimiento::create([
            'item_id' => $item->id,
            'user_id' => Auth::id(),
            'tipo' => 'ALTA',
            'de_estado' => null,
            'a_estado' => $item->estado,
            'de_ubicacion_id' => null,
            'a_ubicacion_id' => $item->ubicacion_id,
            'notas' => 'Alta de item',
            'evidencia_path' => null,
            'fecha' => now(),
        ]);

        return redirect()->route('items.index')->with('success', 'Item creado.');
    }

    public function show(Item $item)
    {
        $item->load([
            'ubicacion',
            'categoria',
            'movimientos.user',
            'movimientos.deUbicacion',
            'movimientos.aUbicacion',
        ]);

        return view('items.show', [
            'item' => $item,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
        ]);
    }

    public function edit(Item $item)
    {
        $categoriasQuery = Categoria::query()->orderBy('nombre');
        if (Schema::hasColumn('categorias', 'activo')) {
            $categoriasQuery->where('activo', true);
        }

        return view('items.edit', [
            'item' => $item,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'categorias' => $categoriasQuery->get(),
            'estados' => Item::ESTADOS,
        ]);
    }

    public function update(UpdateItemRequest $request, Item $item)
    {
        $data = $request->validated();
        $data['delete_foto'] = $request->boolean('delete_foto');

        $beforeEstado = $item->estado;
        $beforeUbicacion = $item->ubicacion_id;

        $toEstado = $data['estado'] ?? $item->estado;
        if ($beforeEstado !== $toEstado && !Item::canTransition($beforeEstado, $toEstado)) {
            return back()->withErrors([
                'estado' => "No se permite cambiar de {$beforeEstado} a {$toEstado}.",
            ])->withInput();
        }

        // No permitas que te pisen el código
        unset($data['codigo'], $data['codigo_seq']);

        // Ya no existe columna legacy "categoria"
        unset($data['categoria']);

        // Borrar foto
        if ($data['delete_foto'] && Schema::hasColumn('items', 'foto_path')) {
            if ($item->foto_path && Storage::disk('public')->exists($item->foto_path)) {
                Storage::disk('public')->delete($item->foto_path);
            }
            $data['foto_path'] = null;
        }
        unset($data['delete_foto']);

        // Foto nueva (reemplaza)
        if ($request->hasFile('foto') && Schema::hasColumn('items', 'foto_path')) {
            if ($item->foto_path && Storage::disk('public')->exists($item->foto_path)) {
                Storage::disk('public')->delete($item->foto_path);
            }
            $data['foto_path'] = $request->file('foto')->store('items', 'public');
        }
        unset($data['foto']);

        $item->update($data);

        $changedEstado = $beforeEstado !== $item->estado;
        $changedUbicacion = (string) $beforeUbicacion !== (string) $item->ubicacion_id;

        if ($changedEstado || $changedUbicacion) {
            Movimiento::create([
                'item_id' => $item->id,
                'user_id' => Auth::id(),
                'tipo' => $changedEstado && $changedUbicacion
                    ? 'AJUSTE'
                    : ($changedEstado ? 'CAMBIO_ESTADO' : 'TRASLADO'),
                'de_estado' => $beforeEstado,
                'a_estado' => $item->estado,
                'de_ubicacion_id' => $beforeUbicacion,
                'a_ubicacion_id' => $item->ubicacion_id,
                'notas' => 'Actualización de item',
                'evidencia_path' => null,
                'fecha' => now(),
            ]);
        }

        return redirect()->route('items.show', $item)->with('success', 'Item actualizado.');
    }

    public function changeEstado(Request $request, $id)
    {
        $item = Item::findOrFail($id);

        $data = $request->validate([
            'estado' => ['required', Rule::in(Item::ESTADOS)],
            'notas' => ['nullable', 'string', 'max:1000'],
            'evidencia' => ['nullable', 'file', 'max:4096'],
        ]);

        $from = $item->estado;
        $to = $data['estado'];

        if ($from !== $to && !Item::canTransition($from, $to)) {
            return back()->withErrors([
                'estado' => "No se permite cambiar de {$from} a {$to}.",
            ])->withInput();
        }

        if ($from === $to) {
            return back()->with('success', "Estado sin cambios ({$to}).");
        }

        $evidenciaPath = null;
        if ($request->hasFile('evidencia')) {
            $evidenciaPath = $request->file('evidencia')->store('movimientos', 'public');
        }

        $ubicacionActual = $item->ubicacion_id;

        $item->update(['estado' => $to]);

        Movimiento::create([
            'item_id' => $item->id,
            'user_id' => Auth::id(),
            'tipo' => $to === 'BAJA' ? 'BAJA' : ($to === 'VENDIDO' ? 'VENTA' : 'CAMBIO_ESTADO'),
            'de_estado' => $from,
            'a_estado' => $to,
            'de_ubicacion_id' => $ubicacionActual,
            'a_ubicacion_id' => $ubicacionActual,
            'notas' => $data['notas'] ?? 'Cambio de estado',
            'evidencia_path' => $evidenciaPath,
            'fecha' => now(),
        ]);

        return back()->with('success', "Estado actualizado a {$to}.");
    }

    public function moveUbicacion(Request $request, $id)
    {
        $item = Item::findOrFail($id);

        $data = $request->validate([
            'ubicacion_id' => ['nullable', 'exists:ubicaciones,id'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'evidencia' => ['nullable', 'file', 'max:4096'],
        ]);

        $fromU = $item->ubicacion_id;
        $toU = $data['ubicacion_id'] ?? null;

        if ((string) $fromU === (string) $toU) {
            return back()->with('success', 'Ubicación sin cambios.');
        }

        $evidenciaPath = null;
        if ($request->hasFile('evidencia')) {
            $evidenciaPath = $request->file('evidencia')->store('movimientos', 'public');
        }

        $estadoActual = $item->estado;

        $item->update(['ubicacion_id' => $toU]);

        Movimiento::create([
            'item_id' => $item->id,
            'user_id' => Auth::id(),
            'tipo' => 'TRASLADO',
            'de_estado' => $estadoActual,
            'a_estado' => $estadoActual,
            'de_ubicacion_id' => $fromU,
            'a_ubicacion_id' => $toU,
            'notas' => $data['notas'] ?? 'Movimiento de ubicación',
            'evidencia_path' => $evidenciaPath,
            'fecha' => now(),
        ]);

        return back()->with('success', 'Ubicación actualizada.');
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
            'user_id' => Auth::id(),
            'tipo' => 'RESTAURAR',
            'de_estado' => null,
            'a_estado' => $item->estado,
            'de_ubicacion_id' => null,
            'a_ubicacion_id' => $item->ubicacion_id,
            'notas' => 'Item restaurado desde papelera',
            'evidencia_path' => null,
            'fecha' => now(),
        ]);

        return redirect()->route('items.trash')->with('success', 'Item restaurado.');
    }

    public function forceDelete($id)
    {
        $item = Item::onlyTrashed()->findOrFail($id);

        if (Schema::hasColumn('items', 'foto_path')) {
            if ($item->foto_path && Storage::disk('public')->exists($item->foto_path)) {
                Storage::disk('public')->delete($item->foto_path);
            }
        }

        $item->forceDelete();

        return redirect()->route('items.trash')->with('success', 'Item eliminado permanentemente.');
    }

    public function destroy(Item $item)
    {
        $item->delete();
        return redirect()->route('items.index')->with('success', 'Item enviado a papelera.');
    }
}
