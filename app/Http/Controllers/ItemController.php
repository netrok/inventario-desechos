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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ItemController extends Controller
{
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
        if (! Schema::hasColumn('items', 'foto_path')) {
            return;
        }

        if ($fotoPath && Storage::disk('public')->exists($fotoPath)) {
            Storage::disk('public')->delete($fotoPath);
        }
    }

    private function storeNewFotoIfPresent(Request $request): ?string
    {
        if (! Schema::hasColumn('items', 'foto_path')) {
            return null;
        }

        if (! $request->hasFile('foto')) {
            return null;
        }

        return $request->file('foto')->store('items', 'public');
    }

    private function deleteEvidenciaIfExists(?string $evidenciaPath): void
    {
        if ($evidenciaPath && Storage::disk('public')->exists($evidenciaPath)) {
            Storage::disk('public')->delete($evidenciaPath);
        }
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

        $fotoPath = $this->storeNewFotoIfPresent($request);
        unset($data['foto']);

        try {
            $item = DB::transaction(function () use ($data, $fotoPath) {
                $item = Item::create($data + ['foto_path' => $fotoPath]);

                Movimiento::create([
                    'item_id' => $item->id,
                    'user_id' => Auth::id(),
                    'tipo' => Movimiento::TIPO_ALTA,
                    'de_estado' => null,
                    'a_estado' => $item->estado,
                    'de_ubicacion_id' => null,
                    'a_ubicacion_id' => $item->ubicacion_id,
                    'notas' => 'Alta de item',
                    'evidencia_path' => null,
                ]);

                return $item;
            });
        } catch (\Throwable $e) {
            $this->deleteFotoIfExists($fotoPath);
            throw $e;
        }

        if ($request->boolean('save_and_new')) {
            return redirect()->route('items.create')
                ->withInput([
                    'categoria_id' => $data['categoria_id'] ?? '',
                    'ubicacion_id' => $data['ubicacion_id'] ?? '',
                    'estado' => $data['estado'] ?? Item::ESTADOS[0],
                ])
                ->with('success', "Item {$item->codigo} creado correctamente.");
        }

        return redirect()->route('items.index')->with('success', "Item {$item->codigo} creado.");
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

    /**
     * Pantalla de escaneo/búsqueda por código (scanner USB tipo teclado).
     * El código normalizado (trim + uppercase) resuelve de forma determinista.
     */
    public function scan(Request $request)
    {
        $codigo = trim((string) $request->query('codigo', ''));
        $error = null;

        if ($codigo !== '') {
            $normalized = strtoupper($codigo);

            if (mb_strlen($normalized) > 40) {
                $error = 'El código es demasiado largo.';
            } else {
                $item = Item::query()->where('codigo', $normalized)->first();

                if ($item instanceof Item) {
                    return redirect()->route('items.show', $item);
                }

                $error = "No existe un equipo con el código {$normalized}.";
            }
        }

        return view('items.scan', [
            'error' => $error,
            'last_codigo' => $codigo,
        ]);
    }

    /**
     * Etiqueta imprimible de un Item (identificación/consulta).
     */
    public function label(Item $item)
    {
        $item->loadMissing('categoria');

        return view('items.label', ['item' => $item]);
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

        $toEstado = $data['estado'] ?? $item->estado;
        if ($item->estado !== $toEstado && ! Item::canTransition($item->estado, $toEstado)) {
            return back()->withErrors([
                'estado' => "No se permite cambiar de {$item->estado} a {$toEstado}.",
            ])->withInput();
        }

        unset($data['codigo'], $data['codigo_seq']); // no override
        unset($data['categoria']); // legacy eliminado
        unset($data['foto'], $data['delete_foto']);

        $newFotoPath = $this->storeNewFotoIfPresent($request);

        $replacedFotoPath = null;

        try {
            DB::transaction(function () use ($item, $data, $deleteFoto, $newFotoPath, &$replacedFotoPath): void {
                $locked = Item::query()->lockForUpdate()->findOrFail($item->getKey());

                $beforeEstado = $locked->estado;
                $beforeUbicacion = $locked->ubicacion_id;
                $beforeFotoPath = $locked->foto_path;

                $toEstadoLocked = $data['estado'] ?? $beforeEstado;
                if ($beforeEstado !== $toEstadoLocked && ! Item::canTransition($beforeEstado, $toEstadoLocked)) {
                    throw ValidationException::withMessages([
                        'estado' => "No se permite cambiar de {$beforeEstado} a {$toEstadoLocked}.",
                    ]);
                }

                $payload = $data;
                $payload['foto_path'] = $newFotoPath !== null
                    ? $newFotoPath
                    : ($deleteFoto ? null : $beforeFotoPath);

                $locked->update($payload);

                $changedEstado = $beforeEstado !== $locked->estado;
                $changedUbicacion = (string) $beforeUbicacion !== (string) $locked->ubicacion_id;

                if ($changedEstado || $changedUbicacion) {
                    Movimiento::create([
                        'item_id' => $locked->id,
                        'user_id' => Auth::id(),
                        'tipo' => $changedEstado && $changedUbicacion
                            ? Movimiento::TIPO_AJUSTE
                            : ($changedEstado ? Movimiento::TIPO_CAMBIO_ESTADO : Movimiento::TIPO_TRASLADO),
                        'de_estado' => $beforeEstado,
                        'a_estado' => $locked->estado,
                        'de_ubicacion_id' => $beforeUbicacion,
                        'a_ubicacion_id' => $locked->ubicacion_id,
                        'notas' => 'Actualización de item',
                        'evidencia_path' => null,
                    ]);
                }

                if ($deleteFoto || $newFotoPath !== null) {
                    $replacedFotoPath = $beforeFotoPath;
                }
            });
        } catch (\Throwable $e) {
            $this->deleteFotoIfExists($newFotoPath);
            throw $e;
        }

        // La transacción se confirmó: recién aquí se elimina la foto anterior realmente reemplazada
        // (leída bajo lock como fuente de verdad, no la instancia route-model previa).
        if ($replacedFotoPath !== null) {
            $this->deleteFotoIfExists($replacedFotoPath);
        }

        return redirect()->route('items.show', $item)->with('success', 'Item actualizado.');
    }

    public function changeEstado(Request $request, $id)
    {
        $data = $request->validate([
            'estado' => ['required', Rule::in(Item::ESTADOS)],
            'notas' => ['nullable', 'string', 'max:1000'],
            'evidencia' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],
        ]);

        $item = Item::findOrFail($id);

        if ($item->estado === $data['estado']) {
            return back()->with('success', "Estado sin cambios ({$data['estado']}).");
        }

        $evidenciaPath = null;

        try {
            $changed = DB::transaction(function () use ($request, $id, $data, &$evidenciaPath): bool {
                $locked = Item::query()->lockForUpdate()->findOrFail($id);

                $from = $locked->estado;
                $to = $data['estado'];

                if ($from === $to) {
                    return false;
                }

                if (! Item::canTransition($from, $to)) {
                    throw ValidationException::withMessages([
                        'estado' => "No se permite cambiar de {$from} a {$to}.",
                    ]);
                }

                $evidenciaPath = $request->hasFile('evidencia')
                    ? $request->file('evidencia')->store('movimientos', 'public')
                    : null;

                $ubicacionActual = $locked->ubicacion_id;

                $locked->update(['estado' => $to]);

                Movimiento::create([
                    'item_id' => $locked->id,
                    'user_id' => Auth::id(),
                    'tipo' => $to === 'BAJA' ? Movimiento::TIPO_BAJA : ($to === 'VENDIDO' ? Movimiento::TIPO_VENTA : Movimiento::TIPO_CAMBIO_ESTADO),
                    'de_estado' => $from,
                    'a_estado' => $to,
                    'de_ubicacion_id' => $ubicacionActual,
                    'a_ubicacion_id' => $ubicacionActual,
                    'notas' => $data['notas'] ?? 'Cambio de estado',
                    'evidencia_path' => $evidenciaPath,
                ]);

                return true;
            });
        } catch (\Throwable $e) {
            $this->deleteEvidenciaIfExists($evidenciaPath);
            throw $e;
        }

        if (! $changed) {
            return back()->with('success', "Estado sin cambios ({$data['estado']}).");
        }

        return back()->with('success', "Estado actualizado a {$data['estado']}.");
    }

    public function moveUbicacion(Request $request, $id)
    {
        $data = $request->validate([
            'ubicacion_id' => ['nullable', 'exists:ubicaciones,id'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'evidencia' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],
        ]);

        $item = Item::findOrFail($id);
        $toU = $data['ubicacion_id'] ?? null;

        if ((string) $item->ubicacion_id === (string) $toU) {
            return back()->with('success', 'Ubicación sin cambios.');
        }

        $evidenciaPath = null;

        try {
            $moved = DB::transaction(function () use ($request, $id, $toU, $data, &$evidenciaPath): bool {
                $locked = Item::query()->lockForUpdate()->findOrFail($id);

                $fromU = $locked->ubicacion_id;

                if ((string) $fromU === (string) $toU) {
                    return false;
                }

                $evidenciaPath = $request->hasFile('evidencia')
                    ? $request->file('evidencia')->store('movimientos', 'public')
                    : null;

                $estadoActual = $locked->estado;

                $locked->update(['ubicacion_id' => $toU]);

                Movimiento::create([
                    'item_id' => $locked->id,
                    'user_id' => Auth::id(),
                    'tipo' => Movimiento::TIPO_TRASLADO,
                    'de_estado' => $estadoActual,
                    'a_estado' => $estadoActual,
                    'de_ubicacion_id' => $fromU,
                    'a_ubicacion_id' => $toU,
                    'notas' => $data['notas'] ?? 'Movimiento de ubicación',
                    'evidencia_path' => $evidenciaPath,
                ]);

                return true;
            });
        } catch (\Throwable $e) {
            $this->deleteEvidenciaIfExists($evidenciaPath);
            throw $e;
        }

        if (! $moved) {
            return back()->with('success', 'Ubicación sin cambios.');
        }

        return back()->with('success', 'Ubicación actualizada.');
    }

    public function exportXlsx(Request $request)
    {
        $filters = $this->filtersFromRequest($request);

        $query = $this->baseQuery($filters)->orderByDesc('id');
        $filename = 'items_'.now()->format('Ymd_His').'.xlsx';

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

        return $pdf->download('items_'.now()->format('Ymd_His').'.pdf');
    }
}
