<?php

namespace App\Http\Controllers;

use App\Exports\MovimientosExport;
use App\Exports\ReportInventoryExport;
use App\Models\Categoria;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\Ubicacion;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    /**
     * Tipos de movimiento históricos y vigentes.
     */
    private const TIPOS = [
        Movimiento::TIPO_ALTA,
        Movimiento::TIPO_CAMBIO_ESTADO,
        Movimiento::TIPO_TRASLADO,
        Movimiento::TIPO_AJUSTE,
        Movimiento::TIPO_BAJA,
        Movimiento::TIPO_VENTA,
        Movimiento::TIPO_RESTAURAR,
    ];

    public function index()
    {
        return view('reports.index');
    }

    /*
     * ============== INVENTARIO ==============
     */

    private function inventoryFilters(Request $request): array
    {
        $validated = $request->validate([
            'alta_desde' => ['nullable', 'date'],
            'alta_hasta' => ['nullable', 'date'],
            'codigo' => ['nullable', 'string', 'max:40'],
            'marca' => ['nullable', 'string', 'max:80'],
            'modelo' => ['nullable', 'string', 'max:120'],
            'serie' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', Rule::in(Item::ESTADOS)],
            'ubicacion_id' => ['nullable', 'integer', 'exists:ubicaciones,id'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
        ]);

        $ubicacionId = ! empty($validated['ubicacion_id']) ? $validated['ubicacion_id'] : null;
        $categoriaId = ! empty($validated['categoria_id']) ? $validated['categoria_id'] : null;

        $altaDesde = ! empty($validated['alta_desde']) ? Carbon::parse($validated['alta_desde']) : null;
        $altaHasta = ! empty($validated['alta_hasta']) ? Carbon::parse($validated['alta_hasta']) : null;

        if ($altaDesde && $altaHasta && $altaHasta->lt($altaDesde)) {
            throw ValidationException::withMessages([
                'alta_hasta' => 'La fecha "hasta" debe ser posterior o igual a "desde".',
            ]);
        }

        return [
            'codigo' => trim((string) ($validated['codigo'] ?? '')),
            'alta_desde' => $altaDesde,
            'alta_hasta' => $altaHasta,
            'marca' => trim((string) ($validated['marca'] ?? '')),
            'modelo' => trim((string) ($validated['modelo'] ?? '')),
            'serie' => trim((string) ($validated['serie'] ?? '')),
            'estado' => $validated['estado'] ?? null,
            'ubicacion_id' => $ubicacionId,
            'categoria_id' => $categoriaId,

            // Nombres legibles para PDF/chips (evita exponer IDs)
            'ubicacion_name' => $ubicacionId
                ? Ubicacion::query()->whereKey($ubicacionId)->value('nombre')
                : null,
            'categoria_name' => $categoriaId
                ? Categoria::query()->whereKey($categoriaId)->value('nombre')
                : null,
        ];
    }

    /**
     * Query base del inventario con filtros (web + XLSX + PDF comparten esta lógica).
     */
    private function inventoryQuery(array $filters): Builder
    {
        $codigo = $filters['codigo'] ?? '';
        $marca = $filters['marca'] ?? '';
        $modelo = $filters['modelo'] ?? '';
        $serie = $filters['serie'] ?? '';
        $estado = $filters['estado'] ?? null;
        $ubicacionId = $filters['ubicacion_id'] ?? null;
        $categoriaId = $filters['categoria_id'] ?? null;
        $altaDesde = $filters['alta_desde'] ?? null;
        $altaHasta = $filters['alta_hasta'] ?? null;

        return Item::query()
            ->with(['ubicacion:id,nombre', 'categoria:id,nombre'])
            ->when($codigo !== '', fn (Builder $qq) => $qq->where('codigo', 'ilike', "%{$codigo}%"))
            ->when($marca !== '', fn (Builder $qq) => $qq->where('marca', 'ilike', "%{$marca}%"))
            ->when($modelo !== '', fn (Builder $qq) => $qq->where('modelo', 'ilike', "%{$modelo}%"))
            ->when($serie !== '', fn (Builder $qq) => $qq->where('serie', 'ilike', "%{$serie}%"))
            ->when($estado, fn (Builder $qq) => $qq->where('estado', $estado))
            ->when($ubicacionId, fn (Builder $qq) => $qq->where('ubicacion_id', $ubicacionId))
            ->when($categoriaId, fn (Builder $qq) => $qq->where('categoria_id', $categoriaId))
            ->when($altaDesde, fn (Builder $qq) => $qq->where('created_at', '>=', $altaDesde->copy()->startOfDay()))
            ->when($altaHasta, fn (Builder $qq) => $qq->where('created_at', '<=', $altaHasta->copy()->endOfDay()));
    }

    public function inventory(Request $request)
    {
        $filters = $this->inventoryFilters($request);
        $base = $this->inventoryQuery($filters);

        $items = (clone $base)->orderByDesc('id')->paginate(25)->withQueryString();
        $total = (clone $base)->count();

        return view('reports.inventory', [
            'items' => $items,
            'total' => $total,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'categorias' => Categoria::orderBy('nombre')->get(),
            'estados' => Item::ESTADOS,
            'filters' => $filters,
        ]);
    }

    public function inventoryXlsx(Request $request)
    {
        $filters = $this->inventoryFilters($request);
        $query = $this->inventoryQuery($filters)->orderByDesc('id');

        return Excel::download(new ReportInventoryExport($query), $this->filename('reports_inventory', 'xlsx'));
    }

    public function inventoryPdf(Request $request)
    {
        $filters = $this->inventoryFilters($request);
        $items = $this->inventoryQuery($filters)->orderByDesc('id')->get();

        $pdf = Pdf::loadView('reports.pdf.inventory', [
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

        return $pdf->download($this->filename('reports_inventory', 'pdf'));
    }

    /*
     * ============== MOVIMIENTOS ==============
     */

    private function movimientosFiltersFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'usuario_id' => ['nullable', 'integer', 'exists:users,id'],
            'tipo' => ['nullable', Rule::in(self::TIPOS)],
            'codigo' => ['nullable', 'string', 'max:40'],
            'ubicacion_origen_id' => ['nullable', 'integer', 'exists:ubicaciones,id'],
            'ubicacion_destino_id' => ['nullable', 'integer', 'exists:ubicaciones,id'],
        ]);

        $desde = ! empty($validated['desde']) ? Carbon::parse($validated['desde']) : null;
        $hasta = ! empty($validated['hasta']) ? Carbon::parse($validated['hasta']) : null;

        if ($desde && $hasta && $hasta->lt($desde)) {
            throw ValidationException::withMessages([
                'hasta' => 'La fecha "hasta" debe ser posterior o igual a "desde".',
            ]);
        }

        $usuarioId = $validated['usuario_id'] ?? null;
        $origenId = $validated['ubicacion_origen_id'] ?? null;
        $destinoId = $validated['ubicacion_destino_id'] ?? null;

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'usuario_id' => $usuarioId,
            'usuario_name' => $usuarioId ? User::query()->whereKey($usuarioId)->value('name') : null,
            'tipo' => $validated['tipo'] ?? null,
            'codigo' => trim((string) ($validated['codigo'] ?? '')),
            'ubicacion_origen_id' => $origenId,
            'ubicacion_origen_name' => $origenId ? Ubicacion::query()->whereKey($origenId)->value('nombre') : null,
            'ubicacion_destino_id' => $destinoId,
            'ubicacion_destino_name' => $destinoId ? Ubicacion::query()->whereKey($destinoId)->value('nombre') : null,
        ];
    }

    /**
     * Query base de movimientos con filtros (web + XLSX + PDF comparten esta lógica).
     */
    private function movimientosQuery(array $filters): Builder
    {
        $desde = $filters['desde'] ?? null;
        $hasta = $filters['hasta'] ?? null;
        $codigo = $filters['codigo'] ?? '';

        return Movimiento::query()
            ->with(['item:id,codigo', 'user:id,name', 'deUbicacion:id,nombre', 'aUbicacion:id,nombre'])
            ->when($desde, fn (Builder $qq) => $qq->where('created_at', '>=', $desde->copy()->startOfDay()))
            ->when($hasta, fn (Builder $qq) => $qq->where('created_at', '<=', $hasta->copy()->endOfDay()))
            ->when($filters['usuario_id'] ?? null, fn (Builder $qq) => $qq->where('user_id', $filters['usuario_id']))
            ->when($filters['tipo'] ?? null, fn (Builder $qq) => $qq->where('tipo', $filters['tipo']))
            ->when($codigo !== '', function (Builder $qq) use ($codigo) {
                $qq->whereHas('item', fn (Builder $itemQ) => $itemQ->where('codigo', 'ilike', "%{$codigo}%"));
            })
            ->when(
                $filters['ubicacion_origen_id'] ?? null,
                fn (Builder $qq) => $qq->where('de_ubicacion_id', $filters['ubicacion_origen_id'])
            )
            ->when(
                $filters['ubicacion_destino_id'] ?? null,
                fn (Builder $qq) => $qq->where('a_ubicacion_id', $filters['ubicacion_destino_id'])
            );
    }

    public function movimientos(Request $request)
    {
        $filters = $this->movimientosFiltersFromRequest($request);
        $base = $this->movimientosQuery($filters);

        $movimientos = (clone $base)->paginate(25)->withQueryString();
        $total = (clone $base)->count();

        return view('reports.movimientos', [
            'movimientos' => $movimientos,
            'total' => $total,
            'usuarios' => User::orderBy('name')->get(),
            'tipos' => self::TIPOS,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'filters' => $filters,
        ]);
    }

    public function movimientosXlsx(Request $request)
    {
        $filters = $this->movimientosFiltersFromRequest($request);
        $query = $this->movimientosQuery($filters);

        return Excel::download(new MovimientosExport($query), $this->filename('reports_movimientos', 'xlsx'));
    }

    public function movimientosPdf(Request $request)
    {
        $filters = $this->movimientosFiltersFromRequest($request);
        $movimientos = $this->movimientosQuery($filters)->get();

        $pdf = Pdf::loadView('reports.pdf.movimientos', [
            'movimientos' => $movimientos,
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

        return $pdf->download($this->filename('reports_movimientos', 'pdf'));
    }

    private function filename(string $prefix, string $ext): string
    {
        return $prefix.'_'.now()->format('Ymd_His').'.'.$ext;
    }
}
