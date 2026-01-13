<?php

namespace App\Http\Controllers;

use App\Exports\ItemsExport;
use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Models\Categoria;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\Ubicacion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class ItemController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:items.ver')->only(['index', 'show', 'exportXlsx', 'exportPdf']);
        $this->middleware('permission:items.crear')->only(['create', 'store']);
        $this->middleware('permission:items.editar')->only(['edit', 'update']);
        $this->middleware('permission:items.eliminar')->only(['destroy']);

        $this->middleware('permission:items.papelera')->only(['trash']);
        $this->middleware('permission:items.restaurar')->only(['restore']);
        $this->middleware('permission:items.borrar_definitivo')->only(['forceDelete']);

        $this->middleware('permission:items.cambiar_estado')->only(['changeEstado']);
        $this->middleware('permission:items.mover')->only(['moveUbicacion']);
    }

    /**
     * Normaliza filtros desde request (index + export).
     */
    private function filtersFromRequest(Request $request): array
    {
        $ubicacionId = $request->get('ubicacion_id') ?: null;
        $categoriaId = $request->get('categoria_id') ?: null;

        return [
            'q' => trim((string) $request->get('q', '')),
            'estado' => $request->get('estado') ?: null,
            'ubicacion_id' => $ubicacionId,
            'categoria_id' => $categoriaId,

            // Útil para PDF/chips (evita mostrar IDs)
            'ubicacion_name' => $ubicacionId
                ? Ubicacion::query()->whereKey($ubicacionId)->value('nombre')
                : null,
            'categoria_name' => $categoriaId
                ? Categoria::query()->whereKey($categoriaId)->value('nombre')
                : null,
        ];
    }

    /**
     * Query base con filtros (para index + export).
     */
    private function baseQuery(array $filters): Builder
    {
        $q = (string) ($filters['q'] ?? '');
        $estado = $filters['estado'] ?? null;
        $ubicacionId = $filters['ubicacion_id'] ?? null;
        $categoriaId = $filters['categoria_id'] ?? null;

        return Item::query()
            ->with(['ubicacion', 'categoria'])
            ->when($q !== '', function (Builder $qq) use ($q) {
                $qq->where(function (Builder $w) use ($q) {
                    $w->where('codigo', 'ilike', "%{$q}%")
                        ->orWhere('serie', 'ilike', "%{$q}%")
                        ->orWhere('marca', 'ilike', "%{$q}%")
                        ->orWhere('modelo', 'ilike', "%{$q}%");
                });
            })
            ->when($estado, fn (Builder $qq) => $qq->where('estado', $estado))
            ->when($ubicacionId, fn (Builder $qq) => $qq->where('ubicacion_id', $ubicacionId))
            ->when($categoriaId, fn (Builder $qq) => $qq->where('categoria_id', $categoriaId));
    }

    /**
     * Stats del index, sin contaminar el query principal.
     */
    private function buildStats(Builder $base)
    {
        return (clone $base)->selectRaw("
            count(*) as total,
            count(*) filter (where estado='DISPONIBLE') as disponible,
            count(*) filter (where estado='RESERVADO') as reservado,
            count(*) filter (where estado='REPARACION') as reparacion,
            count(*) filter (where estado='VENDIDO') as vendido,
            count(*) filter (where estado='BAJA') as baja
        ")->first();
    }

    private function activeCategorias()
    {
        $q = Categoria::query()->orderBy('nombre');

        if (Schema::hasColumn('categorias', 'activo')) {
            $q->where('activo', true);
        }

        return $q->get();
    }

    private function deleteFotoIfExists(?string $fotoPath): void
    {
        if (!Schema::hasColumn('items', 'foto_path')) {
            return;
        }

        if ($fotoPath && Storage::disk('public')->exists($fotoPath)) {
            Storage::disk('public')->delete($fotoPath);
        }
    }

    private function storeFotoIfPresent(Request $request, ?string $oldPath = null): ?string
    {
        if (!Schema::hasColumn('items', 'foto_path')) {
            return $oldPath;
        }

        if (!$request->hasFile('foto')) {
            return $oldPath;
        }

        // Reemplazo: borra anterior
        $this->deleteFotoIfExists($oldPath);

        return $request->file('foto')->store('items', 'public');
    }

    public function index(Request $request)
    {
        $filters = $this->filtersFromRequest($request);
        $base = $this->baseQuery($filters);

        $stats = $this->buildStats($base);

        $items = (clone $base)
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('items.index', [
            'items' => $items,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'categorias' => $this->activeCategorias(),
            'estados' => Item::ESTADOS,
            'filters' => $filters,
            'stats' => $stats,
            'trashCount' => Item::onlyTrashed()->count(),
        ]);
    }

    public function create()
    {
        return view('items.create', [
            'item' => null,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'categorias' => $this->activeCategorias(),
            'estados' => Item::ESTADOS,
        ]);
    }

    public function store(StoreItemRequest $request)
    {
        $data = $request->validated();

        unset($data['codigo'], $data['codigo_seq']); // lo genera el modelo
        unset($data['categoria']); // legacy eliminado

        $data['foto_path'] = $this->storeFotoIfPresent($request, null);
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
        return view('items.edit', [
            'item' => $item,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'categorias' => $this->activeCategorias(),
            'estados' => Item::ESTADOS,
        ]);
    }

    public function update(UpdateItemRequest $request, Item $item)
    {
        $data = $request->validated();
        $deleteFoto = $request->boolean('delete_foto');

        $beforeEstado = $item->estado;
        $beforeUbicacion = $item->ubicacion_id;

        // Validación transición estado
        $toEstado = $data['estado'] ?? $item->estado;
        if ($beforeEstado !== $toEstado && !Item::canTransition($beforeEstado, $toEstado)) {
            return back()->withErrors([
                'estado' => "No se permite cambiar de {$beforeEstado} a {$toEstado}.",
            ])->withInput();
        }

        unset($data['codigo'], $data['codigo_seq']); // no override
        unset($data['categoria']); // legacy eliminado

        // Foto: borrar
        if ($deleteFoto) {
            $this->deleteFotoIfExists($item->foto_path);
            $data['foto_path'] = null;
        }

        // Foto: nueva (reemplaza). Si no hay foto nueva, conserva lo actual / lo que quede.
        $currentPath = array_key_exists('foto_path', $data) ? $data['foto_path'] : $item->foto_path;
        $data['foto_path'] = $this->storeFotoIfPresent($request, $currentPath);

        unset($data['foto'], $data['delete_foto']);

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

        $evidenciaPath = $request->hasFile('evidencia')
            ? $request->file('evidencia')->store('movimientos', 'public')
            : null;

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

        $evidenciaPath = $request->hasFile('evidencia')
            ? $request->file('evidencia')->store('movimientos', 'public')
            : null;

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
            ->when($q !== '', function (Builder $qq) use ($q) {
                $qq->where(function (Builder $w) use ($q) {
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

        $this->deleteFotoIfExists($item->foto_path);

        $item->forceDelete();

        return redirect()->route('items.trash')->with('success', 'Item eliminado permanentemente.');
    }

    public function destroy(Item $item)
    {
        $item->delete();

        return redirect()->route('items.index')->with('success', 'Item enviado a papelera.');
    }

    public function exportXlsx(Request $request)
    {
        $filters = $this->filtersFromRequest($request);

        $query = $this->baseQuery($filters)->orderByDesc('id');
        $filename = 'items_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new ItemsExport($query), $filename);
    }

    public function exportPdf(Request $request)
    {
        $filters = $this->filtersFromRequest($request);

        $items = $this->baseQuery($filters)
            ->orderByDesc('id')
            ->get();

        $pdf = Pdf::loadView('items.pdf', [
                'items' => $items,
                'filters' => $filters,
                'generatedAt' => now(),
            ])
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'dpi' => 96,
                'defaultFont' => 'DejaVu Sans',
            ]);

        return $pdf->download('items_' . now()->format('Ymd_His') . '.pdf');
    }
}
